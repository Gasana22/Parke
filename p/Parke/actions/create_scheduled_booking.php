<?php
// actions/create_scheduled_booking.php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get database connection
$conn = getConnection();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $location_id = intval($_POST['location_id']);
    $slot_id = intval($_POST['slot_id']);
    $vehicle_number = mysqli_real_escape_string($conn, $_POST['vehicle_number']);
    $start_time = $_POST['start_time'];
    $duration_hours = intval($_POST['duration_hours']);
    
    // Validate inputs
    if (empty($start_time)) {
        $_SESSION['error'] = "Please select a start time.";
        header("Location: ../find_parking.php?view=book&location_id=$location_id&slot_id=$slot_id");
        exit();
    }
    
    if ($duration_hours < 1) {
        $_SESSION['error'] = "Please select a valid duration.";
        header("Location: ../find_parking.php?view=book&location_id=$location_id&slot_id=$slot_id");
        exit();
    }
    
    // Calculate end time
    $end_time = date('Y-m-d H:i:s', strtotime($start_time . " + $duration_hours hours"));
    
    // Check if start time is in the future
    if (strtotime($start_time) < time()) {
        $_SESSION['error'] = "Start time must be in the future.";
        header("Location: ../find_parking.php?view=book&location_id=$location_id&slot_id=$slot_id");
        exit();
    }
    
    // Get price per hour
    $price_query = "SELECT price_per_hour FROM parking_locations WHERE id = ?";
    $price_stmt = $conn->prepare($price_query);
    $price_stmt->bind_param("i", $location_id);
    $price_stmt->execute();
    $price_result = $price_stmt->get_result();
    
    if ($price_result->num_rows == 0) {
        $_SESSION['error'] = "Invalid parking location.";
        header("Location: ../find_parking.php?view=list");
        exit();
    }
    
    $price_row = $price_result->fetch_assoc();
    $price_per_hour = $price_row['price_per_hour'];
    $price_stmt->close();
    
    // Calculate total cost
    $total_cost = $duration_hours * $price_per_hour;
    
    // Check if slot is available for the requested time
    $check_query = "SELECT COUNT(*) as conflict FROM bookings 
                    WHERE slot_id = ? 
                    AND payment_status != 'cancelled'
                    AND (
                        (start_time < ? AND end_time > ?) OR
                        (start_time < ? AND end_time > ?) OR
                        (start_time >= ? AND start_time < ?)
                    )";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("issssss", 
        $slot_id, 
        $end_time, $start_time,
        $end_time, $end_time,
        $start_time, $end_time
    );
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $check_row = $check_result->fetch_assoc();
    
    if ($check_row['conflict'] > 0) {
        $_SESSION['error'] = "Slot is not available for the selected time period.";
        header("Location: ../find_parking.php?view=book&location_id=$location_id&slot_id=$slot_id");
        exit();
    }
    $check_stmt->close();
    
    // Create booking
    $query = "INSERT INTO bookings (user_id, slot_id, location_id, vehicle_number, start_time, end_time, total_cost, payment_status) 
              VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iiisssd", $user_id, $slot_id, $location_id, $vehicle_number, $start_time, $end_time, $total_cost);
    
    if ($stmt->execute()) {
        $booking_id = $stmt->insert_id;
        
        // Update slot status if booking starts immediately or has already started
        if (strtotime($start_time) <= time()) {
            $update_slot = "UPDATE parking_slots SET status = 'occupied', current_vehicle = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_slot);
            $update_stmt->bind_param("si", $vehicle_number, $slot_id);
            $update_stmt->execute();
            $update_stmt->close();
        }
        
        $_SESSION['success'] = "Booking created successfully!";
        header("Location: ../booking.php");
    } else {
        $_SESSION['error'] = "Failed to create booking: " . $conn->error;
        header("Location: ../find_parking.php?view=book&location_id=$location_id&slot_id=$slot_id");
    }
    
    $stmt->close();
} else {
    header("Location: ../find_parking.php");
}

$conn->close();
?>