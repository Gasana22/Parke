<?php
require_once 'db.php';
require_once 'auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $booking_id = intval($_POST['booking_id']);
    $slot_id = intval($_POST['slot_id']);
    $location_id = $_SESSION['location_id'];

    // Get booking details
    $stmt = $conn->prepare("
        SELECT start_time 
        FROM bookings 
        WHERE id = ? AND location_id = ? AND end_time IS NULL
        LIMIT 1
    ");
    $stmt->bind_param("ii", $booking_id, $location_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {

        $booking = $result->fetch_assoc();
        $start_time = strtotime($booking['start_time']);
        $end_time = time();

        $hours = ($end_time - $start_time) / 3600;

        // Get price per hour
        $price_stmt = $conn->prepare("SELECT price_per_hour FROM parking_locations WHERE id = ?");
        $price_stmt->bind_param("i", $location_id);
        $price_stmt->execute();
        $price = $price_stmt->get_result()->fetch_assoc()['price_per_hour'];

        $total_cost = round($hours * $price, 2);

        // Update booking
        $update_booking = $conn->prepare("
            UPDATE bookings 
            SET end_time = NOW(), 
                total_cost = ?, 
                payment_status = 'paid' 
            WHERE id = ?
        ");
        $update_booking->bind_param("di", $total_cost, $booking_id);
        $update_booking->execute();

        // Update slot status
        $update_slot = $conn->prepare("
            UPDATE parking_slots 
            SET status = 'available', current_vehicle = NULL 
            WHERE id = ?
        ");
        $update_slot->bind_param("i", $slot_id);
        $update_slot->execute();

        // Increase available slots count
        $conn->query("
            UPDATE parking_locations 
            SET available_slots = available_slots + 1 
            WHERE id = $location_id
        ");

        // Store success message in session
        session_start();
        $_SESSION['exit_success'] = true;
        $_SESSION['exit_amount'] = $total_cost;
        $_SESSION['exit_vehicle'] = $booking['vehicle_number'] ?? 'Vehicle';

    }

    header("Location: location_dashboard.php");
    exit();
}
?>