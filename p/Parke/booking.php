<?php
// booking.php - My Bookings Page
session_start();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get database connection
$conn = getConnection();

// Get user info for sidebar
$user_query = "SELECT username, email, picture, city FROM users WHERE id = ?";
$user_stmt = $conn->prepare($user_query);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user = $user_result->fetch_assoc();
$user_stmt->close();

// Function to get profile picture path
function get_profile_picture_path($user_id) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT picture FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if (!empty($user['picture']) && file_exists('uploads/profiles/' . $user['picture'])) {
        return 'uploads/profiles/' . $user['picture'];
    }
    return null;
}

$profile_picture = get_profile_picture_path($user_id);

// Get user's current location
$user_city = $_SESSION['user_city'] ?? $user['city'] ?? '';

// Get all bookings for the user
$bookings_query = "SELECT 
    b.*,
    pl.name as location_name,
    pl.address,
    pl.city,
    pl.price_per_hour,
    pl.image_path,
    ps.slot_number,
    ps.slot_type,
    TIMESTAMPDIFF(MINUTE, b.start_time, NOW()) as minutes_elapsed,
    TIMESTAMPDIFF(MINUTE, NOW(), b.end_time) as minutes_remaining
FROM bookings b
JOIN parking_locations pl ON b.location_id = pl.id
JOIN parking_slots ps ON b.slot_id = ps.id
WHERE b.user_id = ?
ORDER BY 
    CASE 
        WHEN b.payment_status = 'pending' AND b.end_time > NOW() THEN 1
        WHEN b.payment_status = 'pending' AND b.end_time <= NOW() THEN 2
        WHEN b.payment_status = 'paid' THEN 3
        WHEN b.payment_status = 'cancelled' THEN 4
    END,
    b.start_time DESC";

$bookings_stmt = $conn->prepare($bookings_query);
$bookings_stmt->bind_param("i", $user_id);
$bookings_stmt->execute();
$bookings_result = $bookings_stmt->get_result();

$all_bookings = [];
while ($row = $bookings_result->fetch_assoc()) {
    $all_bookings[] = $row;
}
$bookings_stmt->close();

// Separate bookings into categories
$upcoming_bookings = [];
$active_bookings = [];
$pending_payment_bookings = [];
$completed_bookings = [];
$cancelled_bookings = [];

foreach ($all_bookings as $booking) {
    $now = time();
    $start = strtotime($booking['start_time']);
    $end = strtotime($booking['end_time']);
    
    if ($booking['payment_status'] == 'cancelled') {
        $cancelled_bookings[] = $booking;
    } elseif ($booking['payment_status'] == 'paid') {
        $completed_bookings[] = $booking;
    } elseif ($booking['payment_status'] == 'pending') {
        if ($now < $start) {
            $upcoming_bookings[] = $booking;
        } elseif ($now >= $start && $now <= $end) {
            $active_bookings[] = $booking;
        } elseif ($now > $end) {
            $pending_payment_bookings[] = $booking;
        }
    }
}

// Handle extend booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['extend_booking'])) {
    $booking_id = intval($_POST['booking_id']);
    $additional_hours = intval($_POST['additional_hours']);
    
    // Get current booking details
    $query = "SELECT b.*, pl.price_per_hour FROM bookings b
              JOIN parking_locations pl ON b.location_id = pl.id
              WHERE b.id = ? AND b.user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $booking_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking = $result->fetch_assoc();
    $stmt->close();
    
    if ($booking) {
        $new_end_time = date('Y-m-d H:i:s', strtotime($booking['end_time'] . " + $additional_hours hours"));
        
        // Check if slot is available for extended time
        $check_query = "SELECT COUNT(*) as conflict FROM bookings 
                       WHERE slot_id = ? 
                       AND payment_status != 'cancelled'
                       AND id != ?
                       AND (
                           (start_time < ? AND end_time > ?) OR
                           (start_time < ? AND end_time > ?) OR
                           (start_time >= ? AND start_time < ?)
                       )";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("iissssss", 
            $booking['slot_id'], 
            $booking_id,
            $new_end_time, $booking['end_time'],
            $new_end_time, $new_end_time,
            $booking['end_time'], $new_end_time
        );
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_row = $check_result->fetch_assoc();
        $check_stmt->close();
        
        if ($check_row['conflict'] == 0) {
            // Calculate additional cost
            $additional_cost = $additional_hours * $booking['price_per_hour'];
            $new_total = $booking['total_cost'] + $additional_cost;
            
            // Update booking
            $update = "UPDATE bookings SET end_time = ?, total_cost = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update);
            $update_stmt->bind_param("sdi", $new_end_time, $new_total, $booking_id);
            
            if ($update_stmt->execute()) {
                $success_message = "Parking extended successfully! New end time: " . date('M d, Y h:i A', strtotime($new_end_time));
            } else {
                $error_message = "Failed to extend parking.";
            }
            $update_stmt->close();
        } else {
            $error_message = "Slot is not available for the requested extension time.";
        }
    }
    
    // Refresh page to show updated bookings
    header("Location: booking.php?" . ($success_message ? "success=" . urlencode($success_message) : "error=" . urlencode($error_message)));
    exit();
}

// Handle cancel booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    $booking_id = intval($_POST['booking_id']);
    
    $update = "UPDATE bookings SET payment_status = 'cancelled' WHERE id = ? AND user_id = ? AND start_time > NOW()";
    $stmt = $conn->prepare($update);
    $stmt->bind_param("ii", $booking_id, $user_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $success_message = "Booking cancelled successfully.";
    } else {
        $error_message = "Cannot cancel this booking. It may have already started or completed.";
    }
    $stmt->close();
    
    header("Location: booking.php?" . ($success_message ? "success=" . urlencode($success_message) : "error=" . urlencode($error_message)));
    exit();
}

// Handle payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_booking'])) {
    $booking_id = intval($_POST['booking_id']);
    
    // Update booking to paid
    $update = "UPDATE bookings SET payment_status = 'paid' WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($update);
    $stmt->bind_param("ii", $booking_id, $user_id);
    
    if ($stmt->execute()) {
        // Update slot status to available
        $slot_update = "UPDATE parking_slots ps 
                        JOIN bookings b ON ps.id = b.slot_id 
                        SET ps.status = 'available', ps.current_vehicle = NULL 
                        WHERE b.id = ?";
        $slot_stmt = $conn->prepare($slot_update);
        $slot_stmt->bind_param("i", $booking_id);
        $slot_stmt->execute();
        $slot_stmt->close();
        
        $success_message = "Payment successful! Thank you for parking with us.";
    } else {
        $error_message = "Payment processing failed.";
    }
    $stmt->close();
    
    header("Location: booking.php?" . ($success_message ? "success=" . urlencode($success_message) : "error=" . urlencode($error_message)));
    exit();
}

// Get success/error messages from URL
$success_message = isset($_GET['success']) ? $_GET['success'] : '';
$error_message = isset($_GET['error']) ? $_GET['error'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings - Parke</title>
    <style>
    :root {
        --primary: #2563eb;
        --primary-light: #3b82f6;
        --primary-dark: #1d4ed8;
        --primary-soft: #dbeafe;
        --secondary: #0ea5e9;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --dark: #1e293b;
        --dark-light: #334155;
        --light: #f8fafc;
        --gray: #64748b;
        --gray-light: #e2e8f0;
        --card-bg: #ffffff;
        
        --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        --gradient-success: linear-gradient(135deg, var(--success) 0%, #34d399 100%);
        --gradient-warning: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%);
        --gradient-danger: linear-gradient(135deg, var(--danger) 0%, #f87171 100%);
        --gradient-light: linear-gradient(135deg, #f0f9ff 0%, #e0f7ff 100%);
        
        --shadow-sm: 0 1px 3px rgba(30, 41, 59, 0.1);
        --shadow: 0 4px 6px -1px rgba(30, 41, 59, 0.1);
        --shadow-lg: 0 10px 15px -3px rgba(30, 41, 59, 0.1);
        --shadow-primary: 0 10px 15px -3px rgba(37, 99, 235, 0.2);
        
        --radius-sm: 8px;
        --radius: 12px;
        --radius-lg: 16px;
        --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    }
    
    body {
        background: var(--gradient-light);
        color: var(--dark);
        min-height: 100vh;
    }
    
    .dashboard {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 20px;
    }
    
    /* Top Navigation */
    .top-nav {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px 0;
        margin-bottom: 20px;
    }
    
    .logo {
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--primary-dark);
        text-decoration: none;
    }
    
    .logo-icon {
        width: 42px;
        height: 42px;
        background: var(--gradient-primary);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
    }
    
    .user-menu {
        display: flex;
        align-items: center;
        gap: 20px;
    }
    
    .user-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .user-avatar-img {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid white;
        box-shadow: var(--shadow);
    }
    
    .user-avatar {
        width: 48px;
        height: 48px;
        background: var(--gradient-primary);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
    }
    
    .user-details h3 {
        font-size: 1rem;
        font-weight: 600;
        color: var(--dark);
    }
    
    .user-details p {
        font-size: 0.85rem;
        color: var(--gray);
    }
    
    /* Dashboard Layout */
    .dashboard-layout {
        display: grid;
        grid-template-columns: 280px 1fr;
        gap: 30px;
    }
    
    /* Sidebar */
    .sidebar {
        background: var(--card-bg);
        border-radius: var(--radius-lg);
        padding: 25px;
        box-shadow: var(--shadow-lg);
        position: sticky;
        top: 20px;
        height: fit-content;
        border: 1px solid var(--gray-light);
    }
    
    .sidebar-nav {
        list-style: none;
        margin-bottom: 30px;
    }
    
    .sidebar-nav li {
        margin-bottom: 8px;
    }
    
    .sidebar-nav a {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 16px;
        color: var(--dark-light);
        text-decoration: none;
        border-radius: var(--radius);
        transition: var(--transition);
        font-weight: 500;
    }
    
    .sidebar-nav a:hover {
        background: var(--primary-soft);
        color: var(--primary);
        transform: translateX(4px);
    }
    
    .sidebar-nav a.active {
        background: var(--gradient-primary);
        color: white;
        box-shadow: var(--shadow-primary);
    }
    
    .sidebar-nav a.active i {
        color: white;
    }
    
    .sidebar-nav i {
        width: 20px;
        text-align: center;
        color: var(--gray);
    }
    
    .location-card {
        background: var(--gradient-light);
        border-radius: var(--radius);
        padding: 20px;
        margin-top: 20px;
        border: 1px solid var(--gray-light);
    }
    
    .location-card h3 {
        font-size: 1rem;
        font-weight: 600;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .location-display {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 15px;
    }
    
    .location-display i {
        color: var(--primary);
    }
    
    .change-location {
        width: 100%;
        padding: 10px;
        background: white;
        border: 2px solid var(--gray-light);
        border-radius: var(--radius-sm);
        cursor: pointer;
        transition: var(--transition);
    }
    
    .change-location:hover {
        border-color: var(--primary);
        background: var(--primary-soft);
    }
    
    /* Main Content */
    .main-content {
        display: flex;
        flex-direction: column;
        gap: 30px;
        padding-bottom: 40px;
    }
    
    .page-header {
        margin-bottom: 20px;
        padding: 25px;
        background: white;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border: 1px solid var(--gray-light);
    }
    
    .page-header h1 {
        font-size: 2rem;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 10px;
    }
    
    .page-header p {
        color: var(--gray);
    }
    
    /* Alerts */
    .alert {
        border-radius: var(--radius);
        border: 1px solid;
        padding: 20px;
        margin-bottom: 20px;
    }
    
    .alert-success {
        background: #d1fae5;
        border-color: var(--success);
        color: #065f46;
    }
    
    .alert-danger {
        background: #fee2e2;
        border-color: var(--danger);
        color: #991b1b;
    }
    
    /* Tabs */
    .tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 25px;
        background: white;
        padding: 15px;
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        border: 1px solid var(--gray-light);
        flex-wrap: wrap;
    }
    
    .tab {
        padding: 12px 24px;
        background: transparent;
        border: none;
        color: var(--gray);
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
        border-radius: var(--radius-sm);
        transition: var(--transition);
        position: relative;
    }
    
    .tab:hover {
        color: var(--primary);
        background: var(--primary-soft);
    }
    
    .tab.active {
        color: var(--primary);
        background: var(--primary-soft);
    }
    
    .tab.active::after {
        content: '';
        position: absolute;
        bottom: -15px;
        left: 0;
        right: 0;
        height: 3px;
        background: var(--primary);
        border-radius: 3px;
    }
    
    .tab-content {
        display: none;
        animation: fadeIn 0.3s ease;
    }
    
    .tab-content.active {
        display: block;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    /* Bookings Grid */
    .bookings-grid {
        display: grid;
        gap: 20px;
    }
    
    .booking-card {
        background: white;
        border-radius: var(--radius);
        padding: 25px;
        box-shadow: var(--shadow);
        transition: var(--transition);
        border: 1px solid var(--gray-light);
        position: relative;
        overflow: hidden;
    }
    
    .booking-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: var(--gradient-primary);
        opacity: 0;
        transition: var(--transition);
    }
    
    .booking-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-lg);
    }
    
    .booking-card:hover::before {
        opacity: 1;
    }
    
    .booking-card.upcoming::before {
        background: var(--gradient-primary);
        opacity: 1;
    }
    
    .booking-card.active::before {
        background: var(--gradient-success);
        opacity: 1;
    }
    
    .booking-card.pending::before {
        background: var(--gradient-warning);
        opacity: 1;
    }
    
    .booking-card.completed::before {
        background: linear-gradient(135deg, var(--gray) 0%, var(--dark-light) 100%);
        opacity: 1;
    }
    
    .booking-card.cancelled::before {
        background: var(--gradient-danger);
        opacity: 1;
    }
    
    .booking-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 20px;
    }
    
    .booking-info h3 {
        font-size: 1.25rem;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 5px;
    }
    
    .booking-location {
        color: var(--gray);
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.9rem;
    }
    
    .booking-location i {
        color: var(--primary);
    }
    
    .booking-status {
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        border: 1px solid transparent;
    }
    
    .status-upcoming {
        background: rgba(37, 99, 235, 0.1);
        color: var(--primary);
        border-color: rgba(37, 99, 235, 0.2);
    }
    
    .status-active {
        background: rgba(16, 185, 129, 0.1);
        color: var(--success);
        border-color: rgba(16, 185, 129, 0.2);
    }
    
    .status-pending {
        background: rgba(245, 158, 11, 0.1);
        color: var(--warning);
        border-color: rgba(245, 158, 11, 0.2);
    }
    
    .status-completed {
        background: rgba(100, 116, 139, 0.1);
        color: var(--gray);
        border-color: rgba(100, 116, 139, 0.2);
    }
    
    .status-cancelled {
        background: rgba(239, 68, 68, 0.1);
        color: var(--danger);
        border-color: rgba(239, 68, 68, 0.2);
    }
    
    /* Booking Details Grid */
    .booking-details {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
        padding: 15px;
        background: var(--gradient-light);
        border-radius: var(--radius);
        border: 1px solid var(--gray-light);
    }
    
    .detail-item {
        padding: 10px;
        background: white;
        border-radius: var(--radius-sm);
        border: 1px solid var(--gray-light);
    }
    
    .detail-label {
        font-size: 0.75rem;
        color: var(--gray);
        font-weight: 500;
        margin-bottom: 5px;
    }
    
    .detail-value {
        font-weight: 600;
        color: var(--dark);
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 0.9rem;
    }
    
    .detail-value i {
        color: var(--primary);
        width: 16px;
    }
    
    /* Timer */
    .timer {
        background: var(--gradient-light);
        border-radius: var(--radius);
        padding: 15px;
        margin-bottom: 20px;
        text-align: center;
        border: 1px solid var(--gray-light);
        position: relative;
        overflow: hidden;
    }
    
    .timer-countdown {
        font-size: 2rem;
        font-weight: 700;
        color: var(--primary);
        font-family: 'Courier New', monospace;
    }
    
    .timer-label {
        font-size: 0.85rem;
        color: var(--gray);
    }
    
    /* Cost Summary */
    .cost-summary {
        background: var(--gradient-light);
        border-radius: var(--radius);
        padding: 20px;
        margin-bottom: 20px;
        border: 1px solid var(--gray-light);
    }
    
    .cost-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 10px;
        padding: 5px 0;
        border-bottom: 1px solid rgba(226, 232, 240, 0.5);
    }
    
    .cost-row.total {
        font-weight: 700;
        font-size: 1.1rem;
        padding-top: 15px;
        border-top: 2px solid var(--primary-light);
        margin-top: 10px;
        color: var(--primary);
    }
    
    /* Actions */
    .booking-actions {
        display: flex;
        gap: 12px;
        padding-top: 20px;
        border-top: 1px solid var(--gray-light);
        flex-wrap: wrap;
    }
    
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 12px 24px;
        border-radius: var(--radius);
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
        border: none;
        flex: 1;
    }
    
    .btn-primary {
        background: var(--gradient-primary);
        color: white;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-primary);
    }
    
    .btn-success {
        background: var(--gradient-success);
        color: white;
    }
    
    .btn-success:hover {
        transform: translateY(-2px);
    }
    
    .btn-warning {
        background: var(--gradient-warning);
        color: white;
    }
    
    .btn-warning:hover {
        transform: translateY(-2px);
    }
    
    .btn-danger {
        background: var(--gradient-danger);
        color: white;
    }
    
    .btn-danger:hover {
        transform: translateY(-2px);
    }
    
    .btn-outline {
        background: transparent;
        color: var(--primary);
        border: 2px solid var(--primary);
    }
    
    .btn-outline:hover {
        background: var(--primary-soft);
        transform: translateY(-2px);
    }
    
    .btn-outline-secondary {
        background: transparent;
        color: var(--gray);
        border: 2px solid var(--gray-light);
    }
    
    .btn-outline-secondary:hover {
        background: var(--light);
        border-color: var(--gray);
        color: var(--dark);
    }
    
    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 80px 20px;
        background: white;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border: 1px solid var(--gray-light);
    }
    
    .empty-state i {
        font-size: 3.5rem;
        color: var(--primary);
        margin-bottom: 20px;
        opacity: 0.5;
    }
    
    .empty-state h3 {
        font-size: 1.5rem;
        color: var(--dark);
        margin-bottom: 10px;
    }
    
    .empty-state p {
        color: var(--gray);
        margin-bottom: 25px;
    }
    
    /* Modal */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(30, 41, 59, 0.8);
        backdrop-filter: blur(4px);
        z-index: 1000;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    
    .modal-content {
        background: white;
        border-radius: var(--radius-lg);
        padding: 30px;
        max-width: 500px;
        width: 100%;
        box-shadow: var(--shadow-lg);
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 1px solid var(--gray-light);
    }
    
    .modal-title {
        font-size: 1.3rem;
        font-weight: 700;
        color: var(--dark);
    }
    
    .modal-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        color: var(--gray);
        cursor: pointer;
        transition: var(--transition);
    }
    
    .modal-close:hover {
        color: var(--dark);
        transform: rotate(90deg);
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: var(--dark);
    }
    
    .form-control, .form-select {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid var(--gray-light);
        border-radius: var(--radius-sm);
        transition: var(--transition);
        font-size: 1rem;
    }
    
    .form-control:focus, .form-select:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }
    
    .loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid var(--gray-light);
        border-top: 3px solid var(--primary);
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    /* Responsive */
    @media (max-width: 1024px) {
        .dashboard-layout {
            grid-template-columns: 1fr;
        }
        
        .sidebar {
            position: static;
        }
    }
    
    @media (max-width: 768px) {
        .top-nav {
            flex-direction: column;
            gap: 15px;
        }
        
        .user-menu {
            width: 100%;
            justify-content: center;
        }
        
        .booking-details {
            grid-template-columns: 1fr;
        }
        
        .booking-actions {
            flex-direction: column;
        }
        
        .tabs {
            flex-direction: column;
        }
        
        .tab {
            width: 100%;
            text-align: center;
        }
        
        .tab.active::after {
            display: none;
        }
        
        .booking-header {
            flex-direction: column;
            gap: 15px;
        }
    }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="dashboard">
        <!-- Top Navigation -->
        <div class="top-nav">
            <a href="index.php" class="logo">
                <div class="logo-icon">
                    <i class="fas fa-parking"></i>
                </div>
                <span>Parke</span>
            </a>
            
            <div class="user-menu">
                <div class="user-info">
                    <?php if ($profile_picture): ?>
                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile" class="user-avatar-img">
                    <?php else: ?>
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                    <div class="user-details">
                        <h3><?php echo htmlspecialchars($user['username']); ?></h3>
                        <p><?php echo htmlspecialchars($user['email']); ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main Dashboard Layout -->
        <div class="dashboard-layout">
            <!-- Sidebar -->
            <div class="sidebar">
                <ul class="sidebar-nav">
                    <li>
                        <a href="dashboard.php">
                            <i class="fas fa-home"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="find_parking.php">
                            <i class="fas fa-search"></i>
                            <span>Find Parking</span>
                        </a>
                    </li>
                    <li>
                        <a href="booking.php" class="active">
                            <i class="fas fa-calendar-alt"></i>
                            <span>My Bookings</span>
                            <?php if (count($active_bookings) > 0): ?>
                                <span style="margin-left: auto; background: var(--primary); color: white; width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.7rem;">
                                    <?php echo count($active_bookings); ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="profile.php">
                            <i class="fas fa-user"></i>
                            <span>Profile</span>
                        </a>
                    </li>
                    <li>
                        <a href="favorites.php">
                            <i class="fas fa-star"></i>
                            <span>Favorites</span>
                        </a>
                    </li>
                    <li>
                        <a href="logout.php">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </li>
                </ul>
                
                <div class="location-card">
                    <h3><i class="fas fa-map-marker-alt"></i> Your Location</h3>
                    <div class="location-display">
                        <i class="fas fa-location-dot"></i>
                        <span><?php echo $user_city ? htmlspecialchars($user_city) : 'Not set'; ?></span>
                    </div>
                    <button class="change-location" onclick="openLocationModal()">
                        <i class="fas fa-edit"></i> Change Location
                    </button>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="main-content">
                <?php if ($success_message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>
                
                <div class="page-header">
                    <h1>My Bookings</h1>
                    <p>Manage your current and past parking bookings</p>
                </div>
                
                <div class="tabs">
                    <button class="tab <?php echo (count($upcoming_bookings) > 0 || count($active_bookings) > 0 || count($pending_payment_bookings) > 0) ? 'active' : ''; ?>" onclick="showTab('active')">
                        Active (<?php echo count($upcoming_bookings) + count($active_bookings) + count($pending_payment_bookings); ?>)
                    </button>
                    <button class="tab <?php echo (count($upcoming_bookings) == 0 && count($active_bookings) == 0 && count($pending_payment_bookings) == 0) ? 'active' : ''; ?>" onclick="showTab('history')">
                        History (<?php echo count($completed_bookings) + count($cancelled_bookings); ?>)
                    </button>
                </div>
                
                <!-- Active Tab -->
                <div id="activeTab" class="tab-content <?php echo (count($upcoming_bookings) > 0 || count($active_bookings) > 0 || count($pending_payment_bookings) > 0) ? 'active' : ''; ?>">
                    <?php if (empty($upcoming_bookings) && empty($active_bookings) && empty($pending_payment_bookings)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-check"></i>
                            <h3>No Active Bookings</h3>
                            <p>You don't have any active or upcoming bookings at the moment.</p>
                            <a href="find_parking.php" class="btn btn-primary">
                                <i class="fas fa-search"></i> Find Parking
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="bookings-grid">
                            <!-- Upcoming Bookings -->
                            <?php foreach ($upcoming_bookings as $booking): ?>
                                <div class="booking-card upcoming">
                                    <div class="booking-header">
                                        <div class="booking-info">
                                            <h3><?php echo htmlspecialchars($booking['location_name']); ?></h3>
                                            <div class="booking-location">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <?php echo htmlspecialchars($booking['address']); ?>
                                            </div>
                                        </div>
                                        <div class="booking-status status-upcoming">
                                            <i class="fas fa-clock"></i> Upcoming
                                        </div>
                                    </div>
                                    
                                    <div class="booking-details">
                                        <div class="detail-item">
                                            <div class="detail-label">Slot Number</div>
                                            <div class="detail-value">
                                                <i class="fas fa-parking"></i>
                                                #<?php echo htmlspecialchars($booking['slot_number']); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <div class="detail-label">Slot Type</div>
                                            <div class="detail-value">
                                                <i class="fas fa-car"></i>
                                                <?php echo ucfirst($booking['slot_type']); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <div class="detail-label">Vehicle</div>
                                            <div class="detail-value">
                                                <i class="fas fa-tag"></i>
                                                <?php echo htmlspecialchars($booking['vehicle_number']); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <div class="detail-label">Start Time</div>
                                            <div class="detail-value">
                                                <i class="fas fa-play-circle"></i>
                                                <?php echo date('M d, Y g:i A', strtotime($booking['start_time'])); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <div class="detail-label">End Time</div>
                                            <div class="detail-value">
                                                <i class="fas fa-stop-circle"></i>
                                                <?php echo date('M d, Y g:i A', strtotime($booking['end_time'])); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <div class="detail-label">Duration</div>
                                            <div class="detail-value">
                                                <i class="fas fa-hourglass-half"></i>
                                                <?php 
                                                    $start = new DateTime($booking['start_time']);
                                                    $end = new DateTime($booking['end_time']);
                                                    $diff = $start->diff($end);
                                                    echo $diff->h + ($diff->days * 24) . 'h ' . $diff->i . 'm';
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="cost-summary">
                                        <div class="cost-row">
                                            <span>Hourly Rate:</span>
                                            <span>UGX <?php echo number_format($booking['price_per_hour']); ?></span>
                                        </div>
                                        <div class="cost-row total">
                                            <span>Total Cost:</span>
                                            <span>UGX <?php echo number_format($booking['total_cost']); ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="booking-actions">
                                        <form method="POST" action="" style="flex: 1;">
                                            <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                            <input type="hidden" name="cancel_booking" value="1">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to cancel this booking?')">
                                                <i class="fas fa-times-circle"></i> Cancel Booking
                                            </button>
                                        </form>
                                        
                                        <a href="find_parking.php?location_id=<?php echo $booking['location_id']; ?>" class="btn btn-outline" style="flex: 1;">
                                            <i class="fas fa-directions"></i> View Location
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <!-- Active Bookings -->
                            <?php foreach ($active_bookings as $booking): 
                                $end_time = strtotime($booking['end_time']);
                                $now = time();
                                $remaining = $end_time - $now;
                                $hours_remaining = floor($remaining / 3600);
                                $minutes_remaining = floor(($remaining % 3600) / 60);
                                
                                // Check if extension is possible
                                $extension_possible = true;
                                $check_ext = "SELECT COUNT(*) as future FROM bookings 
                                             WHERE slot_id = ? AND id != ? 
                                             AND start_time > ? 
                                             AND start_time < DATE_ADD(?, INTERVAL 24 HOUR)
                                             AND payment_status != 'cancelled'";
                                $ext_stmt = $conn->prepare($check_ext);
                                $ext_stmt->bind_param("iiss", $booking['slot_id'], $booking['id'], $booking['end_time'], $booking['end_time']);
                                $ext_stmt->execute();
                                $ext_result = $ext_stmt->get_result();
                                $ext_row = $ext_result->fetch_assoc();
                                if ($ext_row['future'] > 0) {
                                    $extension_possible = false;
                                }
                                $ext_stmt->close();
                            ?>
                                <div class="booking-card active">
                                    <div class="booking-header">
                                        <div class="booking-info">
                                            <h3><?php echo htmlspecialchars($booking['location_name']); ?></h3>
                                            <div class="booking-location">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <?php echo htmlspecialchars($booking['address']); ?>
                                            </div>
                                        </div>
                                        <div class="booking-status status-active">
                                            <i class="fas fa-play-circle"></i> Active
                                        </div>
                                    </div>
                                    
                                    <div class="timer">
                                        <div class="timer-countdown" id="timer-<?php echo $booking['id']; ?>">
                                            <?php echo str_pad($hours_remaining, 2, '0', STR_PAD_LEFT); ?>:
                                            <?php echo str_pad($minutes_remaining, 2, '0', STR_PAD_LEFT); ?>:
                                            00
                                        </div>
                                        <div class="timer-label">Time Remaining</div>
                                    </div>
                                    
                                    <div class="booking-details">
                                        <div class="detail-item">
                                            <div class="detail-label">Slot Number</div>
                                            <div class="detail-value">
                                                <i class="fas fa-parking"></i>
                                                #<?php echo htmlspecialchars($booking['slot_number']); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <div class="detail-label">Vehicle</div>
                                            <div class="detail-value">
                                                <i class="fas fa-tag"></i>
                                                <?php echo htmlspecialchars($booking['vehicle_number']); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <div class="detail-label">Started</div>
                                            <div class="detail-value">
                                                <i class="fas fa-play-circle"></i>
                                                <?php echo date('g:i A', strtotime($booking['start_time'])); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <div class="detail-label">Ends</div>
                                            <div class="detail-value">
                                                <i class="fas fa-stop-circle"></i>
                                                <?php echo date('g:i A', strtotime($booking['end_time'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="cost-summary">
                                        <div class="cost-row">
                                            <span>Hourly Rate:</span>
                                            <span>UGX <?php echo number_format($booking['price_per_hour']); ?></span>
                                        </div>
                                        <div class="cost-row total">
                                            <span>Estimated Cost:</span>
                                            <span id="cost-<?php echo $booking['id']; ?>">UGX <?php echo number_format($booking['total_cost']); ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="booking-actions">
                                        <?php if ($extension_possible && $remaining > 0): ?>
                                            <button class="btn btn-warning" onclick="openExtendModal(<?php echo $booking['id']; ?>, <?php echo $booking['price_per_hour']; ?>)">
                                                <i class="fas fa-clock"></i> Extend
                                            </button>
                                        <?php endif; ?>
                                        
                                        <a href="find_parking.php?location_id=<?php echo $booking['location_id']; ?>" class="btn btn-outline">
                                            <i class="fas fa-directions"></i> Directions
                                        </a>
                                    </div>
                                    
                                    <script>
                                        (function() {
                                            const endTime = new Date("<?php echo $booking['end_time']; ?>").getTime();
                                            
                                            function updateTimer<?php echo $booking['id']; ?>() {
                                                const now = new Date().getTime();
                                                const distance = endTime - now;
                                                
                                                if (distance < 0) {
                                                    document.getElementById('timer-<?php echo $booking['id']; ?>').innerHTML = "EXPIRED";
                                                    location.reload();
                                                    return;
                                                }
                                                
                                                const hours = Math.floor(distance / (1000 * 60 * 60));
                                                const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                                                const seconds = Math.floor((distance % (1000 * 60)) / 1000);
                                                
                                                document.getElementById('timer-<?php echo $booking['id']; ?>').innerHTML = 
                                                    hours.toString().padStart(2, '0') + ":" + 
                                                    minutes.toString().padStart(2, '0') + ":" + 
                                                    seconds.toString().padStart(2, '0');
                                                
                                                // Update cost estimate
                                                const totalMinutes = (<?php echo strtotime($booking['end_time']) - strtotime($booking['start_time']); ?> / 60) - ((<?php echo $end_time; ?> - now) / 60000);
                                                const costElement = document.getElementById('cost-<?php echo $booking['id']; ?>');
                                                if (costElement) {
                                                    const hoursParked = totalMinutes / 60;
                                                    const estimatedCost = Math.ceil(hoursParked) * <?php echo $booking['price_per_hour']; ?>;
                                                    costElement.innerHTML = 'UGX ' + estimatedCost.toLocaleString();
                                                }
                                            }
                                            
                                            updateTimer<?php echo $booking['id']; ?>();
                                            setInterval(updateTimer<?php echo $booking['id']; ?>, 1000);
                                        })();
                                    </script>
                                </div>
                            <?php endforeach; ?>
                            
                            <!-- Pending Payment Bookings -->
                            <?php foreach ($pending_payment_bookings as $booking): ?>
                                <div class="booking-card pending">
                                    <div class="booking-header">
                                        <div class="booking-info">
                                            <h3><?php echo htmlspecialchars($booking['location_name']); ?></h3>
                                            <div class="booking-location">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <?php echo htmlspecialchars($booking['address']); ?>
                                            </div>
                                        </div>
                                        <div class="booking-status status-pending">
                                            <i class="fas fa-hourglass-end"></i> Payment Pending
                                        </div>
                                    </div>
                                    
                                    <div class="booking-details">
                                        <div class="detail-item">
                                            <div class="detail-label">Slot Number</div>
                                            <div class="detail-value">
                                                <i class="fas fa-parking"></i>
                                                #<?php echo htmlspecialchars($booking['slot_number']); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <div class="detail-label">Vehicle</div>
                                            <div class="detail-value">
                                                <i class="fas fa-tag"></i>
                                                <?php echo htmlspecialchars($booking['vehicle_number']); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <div class="detail-label">Started</div>
                                            <div class="detail-value">
                                                <i class="fas fa-play-circle"></i>
                                                <?php echo date('M d, g:i A', strtotime($booking['start_time'])); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <div class="detail-label">Ended</div>
                                            <div class="detail-value">
                                                <i class="fas fa-stop-circle"></i>
                                                <?php echo date('M d, g:i A', strtotime($booking['end_time'])); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <div class="detail-label">Duration</div>
                                            <div class="detail-value">
                                                <i class="fas fa-hourglass-half"></i>
                                                <?php 
                                                    $start = new DateTime($booking['start_time']);
                                                    $end = new DateTime($booking['end_time']);
                                                    $diff = $start->diff($end);
                                                    echo $diff->h + ($diff->days * 24) . 'h ' . $diff->i . 'm';
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="cost-summary">
                                        <div class="cost-row">
                                            <span>Hours Parked:</span>
                                            <span>
                                                <?php 
                                                    $start = new DateTime($booking['start_time']);
                                                    $end = new DateTime($booking['end_time']);
                                                    $diff = $start->diff($end);
                                                    $hours = $diff->h + ($diff->days * 24);
                                                    $minutes = $diff->i;
                                                    echo $hours . 'h ' . $minutes . 'm';
                                                ?>
                                            </span>
                                        </div>
                                        <div class="cost-row">
                                            <span>Hourly Rate:</span>
                                            <span>UGX <?php echo number_format($booking['price_per_hour']); ?></span>
                                        </div>
                                        <div class="cost-row total">
                                            <span>Total Due:</span>
                                            <span>UGX <?php echo number_format($booking['total_cost']); ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="booking-actions">
                                        <form method="POST" action="" style="flex: 2;">
                                            <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                            <input type="hidden" name="pay_booking" value="1">
                                            <button type="submit" class="btn btn-success" style="width: 100%;">
                                                <i class="fas fa-credit-card"></i> Pay UGX <?php echo number_format($booking['total_cost']); ?>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- History Tab -->
                <div id="historyTab" class="tab-content <?php echo (count($upcoming_bookings) == 0 && count($active_bookings) == 0 && count($pending_payment_bookings) == 0) ? 'active' : ''; ?>">
                    <?php if (empty($completed_bookings) && empty($cancelled_bookings)): ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <h3>No Booking History</h3>
                            <p>Your completed and cancelled bookings will appear here.</p>
                            <a href="find_parking.php" class="btn btn-primary">
                                <i class="fas fa-search"></i> Find Parking
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="bookings-grid">
                            <!-- Completed Bookings -->
                            <?php foreach ($completed_bookings as $booking): ?>
                                <div class="booking-card completed">
                                    <div class="booking-header">
                                        <div class="booking-info">
                                            <h3><?php echo htmlspecialchars($booking['location_name']); ?></h3>
                                            <div class="booking-location">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <?php echo htmlspecialchars($booking['address']); ?>
                                            </div>
                                        </div>
                                        <div class="booking-status status-completed">
                                            <i class="fas fa-check-circle"></i> Completed
                                        </div>
                                    </div>
                                    
                                    <div class="booking-details">
                                        <div class="detail-item">
                                            <div class="detail-label">Slot</div>
                                            <div class="detail-value">
                                                <i class="fas fa-parking"></i>
                                                #<?php echo htmlspecialchars($booking['slot_number']); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <div class="detail-label">Vehicle</div>
                                            <div class="detail-value">
                                                <i class="fas fa-tag"></i>
                                                <?php echo htmlspecialchars($booking['vehicle_number']); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <div class="detail-label">Date</div>
                                            <div class="detail-value">
                                                <i class="fas fa-calendar"></i>
                                                <?php echo date('M d, Y', strtotime($booking['start_time'])); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <div class="detail-label">Duration</div>
                                            <div class="detail-value">
                                                <i class="fas fa-hourglass-half"></i>
                                                <?php 
                                                    $start = new DateTime($booking['start_time']);
                                                    $end = new DateTime($booking['end_time']);
                                                    $diff = $start->diff($end);
                                                    echo $diff->h + ($diff->days * 24) . 'h ' . $diff->i . 'm';
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="cost-summary">
                                        <div class="cost-row total">
                                            <span>Paid:</span>
                                            <span>UGX <?php echo number_format($booking['total_cost']); ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="booking-actions">
                                        <a href="find_parking.php?location_id=<?php echo $booking['location_id']; ?>" class="btn btn-outline">
                                            <i class="fas fa-redo"></i> Book Again
                                        </a>
                                        <button class="btn btn-outline-secondary" onclick="alert('Receipt has been sent to your email.')">
                                            <i class="fas fa-receipt"></i> Receipt
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <!-- Cancelled Bookings -->
                            <?php foreach ($cancelled_bookings as $booking): ?>
                                <div class="booking-card cancelled">
                                    <div class="booking-header">
                                        <div class="booking-info">
                                            <h3><?php echo htmlspecialchars($booking['location_name']); ?></h3>
                                            <div class="booking-location">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <?php echo htmlspecialchars($booking['address']); ?>
                                            </div>
                                        </div>
                                        <div class="booking-status status-cancelled">
                                            <i class="fas fa-times-circle"></i> Cancelled
                                        </div>
                                    </div>
                                    
                                    <div class="booking-details">
                                        <div class="detail-item">
                                            <div class="detail-label">Slot</div>
                                            <div class="detail-value">
                                                <i class="fas fa-parking"></i>
                                                #<?php echo htmlspecialchars($booking['slot_number']); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <div class="detail-label">Date</div>
                                            <div class="detail-value">
                                                <i class="fas fa-calendar"></i>
                                                <?php echo date('M d, Y', strtotime($booking['start_time'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="booking-actions">
                                        <a href="find_parking.php?location_id=<?php echo $booking['location_id']; ?>" class="btn btn-outline">
                                            <i class="fas fa-redo"></i> Book Again
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Extend Modal -->
    <div class="modal" id="extendModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Extend Parking Time</h2>
                <button class="modal-close" onclick="closeExtendModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="extendForm">
                    <input type="hidden" name="booking_id" id="extend_booking_id">
                    <input type="hidden" name="extend_booking" value="1">
                    
                    <div class="form-group">
                        <label>Additional Hours</label>
                        <select name="additional_hours" class="form-select" id="extend_hours" required>
                            <option value="">Select extension time</option>
                            <option value="1">1 hour</option>
                            <option value="2">2 hours</option>
                            <option value="3">3 hours</option>
                            <option value="4">4 hours</option>
                            <option value="6">6 hours</option>
                        </select>
                    </div>
                    
                    <div class="form-group" id="extendCost" style="background: var(--light); padding: 15px; border-radius: var(--radius);">
                        <div style="display: flex; justify-content: space-between;">
                            <span>Additional Cost:</span>
                            <span style="font-weight: 700; color: var(--primary);" id="extendCostAmount">UGX 0</span>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-clock"></i> Extend Parking
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Location Modal -->
    <div class="modal" id="locationModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Update Your Location</h2>
                <button class="modal-close" onclick="closeLocationModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="GET" action="booking.php">
                    <div class="form-group">
                        <label for="cityInput">City</label>
                        <input type="text" id="cityInput" name="city" class="form-control" 
                               value="<?php echo htmlspecialchars($user_city); ?>" 
                               placeholder="Enter your city">
                    </div>
                    
                    <div style="text-align: center; margin: 20px 0;">
                        <div style="color: var(--gray); margin-bottom: 10px;">or</div>
                        <button type="button" class="btn btn-outline" onclick="useCurrentLocation()" style="width: 100%;">
                            <i class="fas fa-location-arrow"></i> Use Current Location
                        </button>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-save"></i> Update Location
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Tab switching
        function showTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.getElementById(tabName + 'Tab').classList.add('active');
            event.target.classList.add('active');
        }
        
        // Extend modal functions
        function openExtendModal(bookingId, pricePerHour) {
            document.getElementById('extend_booking_id').value = bookingId;
            document.getElementById('extendModal').style.display = 'flex';
            
            // Update cost when hours change
            document.getElementById('extend_hours').addEventListener('change', function() {
                const hours = parseInt(this.value) || 0;
                const cost = hours * pricePerHour;
                document.getElementById('extendCostAmount').innerHTML = 'UGX ' + cost.toLocaleString();
            });
        }
        
        function closeExtendModal() {
            document.getElementById('extendModal').style.display = 'none';
        }
        
        // Location modal functions
        function openLocationModal() {
            document.getElementById('locationModal').style.display = 'flex';
        }
        
        function closeLocationModal() {
            document.getElementById('locationModal').style.display = 'none';
        }
        
        // Close modals when clicking outside
        document.getElementById('extendModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeExtendModal();
            }
        });
        
        document.getElementById('locationModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeLocationModal();
            }
        });
        
        // Use current location
        function useCurrentLocation() {
            const btn = event.target;
            const originalHTML = btn.innerHTML;
            
            btn.innerHTML = '<span class="loading"></span> Getting location...';
            btn.disabled = true;
            
            if (!navigator.geolocation) {
                alert('Geolocation is not supported by your browser.');
                resetButton(btn, originalHTML);
                return;
            }
            
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    alert('Location detected! Please enter your city manually for now.');
                    document.getElementById('cityInput').focus();
                    resetButton(btn, originalHTML);
                },
                function(error) {
                    alert('Unable to retrieve your location. Please enter your city manually.');
                    resetButton(btn, originalHTML);
                }
            );
        }
        
        function resetButton(btn, originalHTML) {
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        }
    </script>
    
    <?php $conn->close(); ?>
</body>
</html>