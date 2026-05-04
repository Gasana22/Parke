<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../find_parking.php?step=1");
    exit();
}

$user_id = $_SESSION['user_id'];
$location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
$slot_id = isset($_POST['slot_id']) ? intval($_POST['slot_id']) : 0;
$vehicle_number = isset($_POST['vehicle_number']) ? trim($_POST['vehicle_number']) : '';

if (empty($vehicle_number) || $location_id <= 0 || $slot_id <= 0) {
    header("Location: ../find_parking.php?step=3&location_id=$location_id&slot_id=$slot_id&error=Invalid+data");
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
        throw new Exception('Failed to create booking');
    }
    
    $booking_id = $conn->insert_id;
    $stmt->close();
    
    // Update slot status to occupied
    $update_slot = $conn->prepare("UPDATE parking_slots SET status = 'occupied', current_vehicle = ? WHERE id = ?");
    $update_slot->bind_param("si", $vehicle_number, $slot_id);
    $update_slot->execute();
    $update_slot->close();
    
    // Update available slots count
    $update_location = $conn->prepare("UPDATE parking_locations SET available_slots = available_slots - 1 WHERE id = ?");
    $update_location->bind_param("i", $location_id);
    $update_location->execute();
    $update_location->close();
    
    $conn->commit();
    
    // REDIRECT DIRECTLY TO STEP 4 - 30 MINUTE TIMER
    header("Location: ../find_parking.php?step=4&booking_id=" . $booking_id);
    exit();
    
} catch (Exception $e) {
    $conn->rollback();
    header("Location: ../find_parking.php?step=3&location_id=$location_id&slot_id=$slot_id&error=" . urlencode($e->getMessage()));
    exit();
}

$conn->close();
?>