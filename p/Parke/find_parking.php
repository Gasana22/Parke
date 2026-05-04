<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? 'driver';

// Get database connection
$conn = getConnection();

// Get user's current location/city
$user_city = $_SESSION['user_city'] ?? $_GET['city'] ?? '';

// Update location if provided
if (isset($_GET['city']) && $_GET['city']) {
    $_SESSION['user_city'] = $_GET['city'];
    $user_city = $_GET['city'];
}

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

// Get user stats for sidebar
$stats_query = "SELECT 
    COUNT(*) as total_bookings,
    SUM(total_cost) as total_spent
    FROM bookings 
    WHERE user_id = ? AND payment_status = 'paid'";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bind_param("i", $user_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();
$stats_stmt->close();

// Get active bookings count (where end_time is in the future)
$active_count_query = "SELECT COUNT(*) as active_count FROM bookings 
                       WHERE user_id = ? AND end_time > NOW() AND payment_status = 'pending'";
$active_stmt = $conn->prepare($active_count_query);
$active_stmt->bind_param("i", $user_id);
$active_stmt->execute();
$active_count_result = $active_stmt->get_result();
$active_count = $active_count_result->fetch_assoc()['active_count'] ?? 0;
$active_stmt->close();

// Get current active booking if any
$active_booking = null;
$active_booking_query = "SELECT b.*, pl.name as location_name, pl.address, ps.slot_number 
                        FROM bookings b 
                        JOIN parking_locations pl ON b.location_id = pl.id 
                        JOIN parking_slots ps ON b.slot_id = ps.id 
                        WHERE b.user_id = ? AND b.end_time > NOW() AND b.payment_status = 'pending'
                        ORDER BY b.start_time ASC LIMIT 1";
$active_booking_stmt = $conn->prepare($active_booking_query);
$active_booking_stmt->bind_param("i", $user_id);
$active_booking_stmt->execute();
$active_booking_result = $active_booking_stmt->get_result();
if ($active_booking_result->num_rows > 0) {
    $active_booking = $active_booking_result->fetch_assoc();
}
$active_booking_stmt->close();

// Handle different views
$view = isset($_GET['view']) ? $_GET['view'] : 'list';
$location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : null;
$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : null;

// Function to check slot availability for a specific time range
function checkSlotAvailability($conn, $slot_id, $start_time, $end_time) {
    $query = "SELECT COUNT(*) as conflict FROM bookings 
              WHERE slot_id = ? 
              AND payment_status != 'cancelled'
              AND (
                  (start_time < ? AND end_time > ?) OR
                  (start_time < ? AND end_time > ?) OR
                  (start_time >= ? AND start_time < ?)
              )";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("issssss", $slot_id, $end_time, $start_time, $end_time, $end_time, $start_time, $end_time);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row['conflict'] == 0;
}

// Function to get predicted availability times for a slot
function getSlotAvailabilityPredictions($conn, $slot_id) {
    $now = date('Y-m-d H:i:s');
    $predictions = [];
    
    // Get current and future bookings for this slot
    $query = "SELECT start_time, end_time FROM bookings 
              WHERE slot_id = ? AND end_time > ? AND payment_status != 'cancelled'
              ORDER BY start_time ASC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("is", $slot_id, $now);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $bookings = [];
    while ($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }
    $stmt->close();
    
    if (empty($bookings)) {
        // Slot is available now
        $predictions[] = [
            'status' => 'available',
            'time' => 'now',
            'label' => 'Available Now'
        ];
    } else {
        // Check if currently available
        $first_booking = $bookings[0];
        if (strtotime($first_booking['start_time']) > time()) {
            $predictions[] = [
                'status' => 'available',
                'time' => 'now',
                'label' => 'Available Now'
            ];
        }
        
        // Add predictions for when slot becomes available
        $last_end = null;
        foreach ($bookings as $booking) {
            if ($last_end && strtotime($booking['start_time']) > strtotime($last_end)) {
                // Gap between bookings
                $gap_start = $last_end;
                $predictions[] = [
                    'status' => 'available_soon',
                    'time' => $gap_start,
                    'label' => 'Available ' . getTimeDifferenceLabel($gap_start)
                ];
            }
            $last_end = $booking['end_time'];
        }
        
        // After last booking
        if ($last_end) {
            $predictions[] = [
                'status' => 'available_soon',
                'time' => $last_end,
                'label' => 'Available ' . getTimeDifferenceLabel($last_end)
            ];
        }
    }
    
    return $predictions;
}

function getTimeDifferenceLabel($time) {
    $diff = strtotime($time) - time();
    if ($diff < 0) return 'now';
    
    $hours = floor($diff / 3600);
    $minutes = floor(($diff % 3600) / 60);
    
    if ($hours > 24) {
        $days = floor($hours / 24);
        return "in $days day" . ($days > 1 ? 's' : '');
    } elseif ($hours > 0) {
        return "in $hours hour" . ($hours > 1 ? 's' : '');
    } elseif ($minutes > 0) {
        return "in $minutes minute" . ($minutes > 1 ? 's' : '');
    } else {
        return 'soon';
    }
}

// Handle extend parking request
if (isset($_POST['extend_booking'])) {
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
        if (checkSlotAvailability($conn, $booking['slot_id'], $booking['end_time'], $new_end_time)) {
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
}

// Handle end parking and payment
if (isset($_POST['end_and_pay'])) {
    $booking_id = intval($_POST['booking_id']);
    
    // Update booking to mark as paid
    $update = "UPDATE bookings SET payment_status = 'paid' WHERE id = ? AND user_id = ?";
    $update_stmt = $conn->prepare($update);
    $update_stmt->bind_param("ii", $booking_id, $user_id);
    
    if ($update_stmt->execute()) {
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
    $update_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Parking - Parke</title>
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
            --gradient-light: linear-gradient(135deg, #f0f9ff 0%, #e0f7ff 100%);
            
            --shadow-sm: 0 1px 3px rgba(30, 41, 59, 0.1);
            --shadow: 0 4px 6px -1px rgba(30, 41, 59, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(30, 41, 59, 0.1);
            
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
            line-height: 1.5;
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
            box-shadow: var(--shadow);
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
            font-size: 1.1rem;
            box-shadow: var(--shadow);
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
        
        .notification-btn {
            width: 48px;
            height: 48px;
            background: white;
            border: 1px solid var(--gray-light);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--dark);
            text-decoration: none;
            position: relative;
            transition: var(--transition);
        }
        
        .notification-btn:hover {
            background: var(--primary-soft);
            border-color: var(--primary-light);
        }
        
        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            width: 20px;
            height: 20px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
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
            box-shadow: var(--shadow);
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
            color: var(--dark);
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
            font-size: 1.2rem;
        }
        
        .location-display span {
            font-weight: 600;
            color: var(--dark);
        }
        
        .change-location {
            width: 100%;
            padding: 10px;
            background: white;
            border: 2px solid var(--gray-light);
            border-radius: var(--radius-sm);
            color: var(--dark);
            font-weight: 500;
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
        
        /* Section Headers */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .section-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            color: var(--primary);
        }
        
        .view-all {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            transition: var(--transition);
        }
        
        .view-all:hover {
            background: var(--primary-soft);
        }
        
        /* Alert Messages */
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
        
        /* Cards */
        .parking-card {
            background: white;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-light);
            transition: var(--transition);
        }
        
        .parking-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }
        
        .parking-image {
            height: 200px;
            overflow: hidden;
            position: relative;
        }
        
        .parking-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        
        .parking-card:hover .parking-image img {
            transform: scale(1.1);
        }
        
        .image-overlay-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        /* Badges */
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .badge-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .badge-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }
        
        .badge-danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        
        .badge-info {
            background: rgba(14, 165, 233, 0.1);
            color: var(--secondary);
            border: 1px solid rgba(14, 165, 233, 0.2);
        }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            border: none;
        }
        
        .btn-primary {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--shadow-sm);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            background: #0da271;
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn-danger:hover {
            background: #dc2626;
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
        
        /* Form Controls */
        .form-control, .form-select {
            width: 100%;
            border: 2px solid var(--gray-light);
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            transition: var(--transition);
            font-size: 1rem;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            outline: none;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
        }
        
        /* Price Display */
        .price-display {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        .price-display small {
            font-size: 0.9rem;
            color: var(--gray);
            font-weight: 400;
        }
        
        /* Grid Layouts */
        .parking-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
        }
        
        /* Availability Tags */
        .availability-tag {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-right: 8px;
            margin-bottom: 8px;
        }
        
        .tag-available {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .tag-soon {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        
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
        
        /* Timer */
        .timer {
            font-family: 'Courier New', monospace;
            font-size: 2rem;
            font-weight: bold;
            text-align: center;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        
        .empty-state i {
            font-size: 3rem;
            color: var(--gray-light);
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            font-size: 1.3rem;
            color: var(--dark);
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: var(--gray);
            margin-bottom: 20px;
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
            
            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .parking-grid {
                grid-template-columns: 1fr;
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
                <a href="#" class="notification-btn">
                    <i class="fas fa-bell"></i>
                    <?php if ($active_count > 0): ?>
                    <span class="notification-badge"><?php echo $active_count; ?></span>
                    <?php endif; ?>
                </a>
                
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
        
        <!-- Dashboard Layout -->
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
                        <a href="find_parking.php" class="active">
                            <i class="fas fa-search"></i>
                            <span>Find Parking</span>
                        </a>
                    </li>
                    <li>
                        <a href="booking.php">
                            <i class="fas fa-calendar-alt"></i>
                            <span>My Bookings</span>
                            <?php if ($active_count > 0): ?>
                                <span style="margin-left: auto; background: var(--primary); color: white; width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.7rem;">
                                    <?php echo $active_count; ?>
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
                
                <!-- Location Card -->
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
                
                <!-- Stats Card -->
                <div class="location-card">
                    <h3><i class="fas fa-chart-bar"></i> Your Stats</h3>
                    <div style="display: flex; flex-direction: column; gap: 15px; margin-top: 15px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: var(--gray);">Total Bookings:</span>
                            <span style="font-weight: 600; color: var(--dark);"><?php echo $stats['total_bookings'] ?? 0; ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--gray-light); padding-top: 10px;">
                            <span style="color: var(--gray);">Total Spent:</span>
                            <span style="font-weight: 600; color: var(--primary);">UGX<?php echo number_format($stats['total_spent'] ?? 0, 0); ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--gray-light); padding-top: 10px;">
                            <span style="color: var(--gray);">Upcoming:</span>
                            <span style="font-weight: 600; color: var(--success);"><?php echo $active_count; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="main-content">
                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>

                <?php
                // VIEW: Parking Lots List
                if ($view == 'list' || !$location_id) {
                    // Build query based on city
                    $query = "SELECT pl.*, 
                             (SELECT COUNT(*) FROM parking_slots ps 
                              WHERE ps.location_id = pl.id) as total_slots_count
                             FROM parking_locations pl 
                             WHERE pl.status = 'active'";
                    
                    $params = [];
                    $types = "";
                    
                    if (!empty($user_city)) {
                        $query .= " AND pl.city = ?";
                        $params[] = $user_city;
                        $types .= "s";
                    }
                    
                    $query .= " ORDER BY pl.name ASC";
                    
                    $stmt = $conn->prepare($query);
                    if (!empty($params)) {
                        $stmt->bind_param($types, ...$params);
                    }
                    $stmt->execute();
                    $result = $stmt->get_result();
                    ?>
                    
                    <div class="section-header">
                        <h2 class="section-title">
                            <i class="fas fa-search"></i> Available Parking in <?php echo $user_city ? htmlspecialchars($user_city) : 'Your Area'; ?>
                        </h2>
                    </div>
                    
                    <?php if ($result->num_rows > 0): ?>
                        <div class="parking-grid">
                            <?php while($row = $result->fetch_assoc()): ?>
                                <?php
                                // Get gallery images
                                $gallery = !empty($row['gallery_images']) ? explode(',', $row['gallery_images']) : [];
                                $main_image = !empty($row['image_path']) && file_exists($row['image_path']) ? $row['image_path'] : (isset($gallery[0]) && file_exists($gallery[0]) ? $gallery[0] : null);
                                
                                // Get available slots and predictions
                                $slots_query = "SELECT id, slot_number, slot_type FROM parking_slots WHERE location_id = ? AND status = 'available'";
                                $slots_stmt = $conn->prepare($slots_query);
                                $slots_stmt->bind_param("i", $row['id']);
                                $slots_stmt->execute();
                                $slots_result = $slots_stmt->get_result();
                                $available_slots = $slots_result->num_rows;
                                $slots_stmt->close();
                                
                                // Get upcoming availability predictions
                                $predictions = [];
                                if ($available_slots == 0) {
                                    // Check next available times
                                    $next_available_query = "SELECT MIN(end_time) as next_available FROM bookings b
                                                            JOIN parking_slots ps ON b.slot_id = ps.id
                                                            WHERE ps.location_id = ? AND b.end_time > NOW()
                                                            ORDER BY b.end_time ASC LIMIT 1";
                                    $next_stmt = $conn->prepare($next_available_query);
                                    $next_stmt->bind_param("i", $row['id']);
                                    $next_stmt->execute();
                                    $next_result = $next_stmt->get_result();
                                    $next_row = $next_result->fetch_assoc();
                                    if ($next_row && $next_row['next_available']) {
                                        $predictions[] = [
                                            'time' => $next_row['next_available'],
                                            'label' => getTimeDifferenceLabel($next_row['next_available'])
                                        ];
                                    }
                                    $next_stmt->close();
                                }
                                ?>
                                
                                <div class="parking-card">
                                    <a href="?view=details&location_id=<?php echo $row['id']; ?>" style="text-decoration: none; color: inherit;">
                                        <div class="parking-image">
                                            <?php if ($main_image): ?>
                                                <img src="<?php echo htmlspecialchars($main_image); ?>" alt="<?php echo htmlspecialchars($row['name']); ?>">
                                            <?php else: ?>
                                                <div style="width: 100%; height: 100%; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-parking" style="font-size: 3rem; color: white; opacity: 0.5;"></i>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($available_slots > 0): ?>
                                                <div class="image-overlay-badge" style="background: var(--success);">
                                                    <i class="fas fa-car"></i> <?php echo $available_slots; ?> spots available
                                                </div>
                                            <?php else: ?>
                                                <div class="image-overlay-badge" style="background: var(--warning);">
                                                    <i class="fas fa-clock"></i> Full
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div style="padding: 20px;">
                                            <h3 style="font-size: 1.2rem; font-weight: 700; color: var(--dark); margin-bottom: 5px;">
                                                <?php echo htmlspecialchars($row['name']); ?>
                                            </h3>
                                            
                                            <p style="color: var(--gray); font-size: 0.9rem; margin-bottom: 10px;">
                                                <i class="fas fa-map-marker-alt" style="color: var(--primary);"></i> 
                                                <?php echo htmlspecialchars($row['city']); ?>
                                            </p>
                                            
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                                <span class="price-display">
                                                    UGX<?php echo number_format($row['price_per_hour']); ?><small>/hour</small>
                                                </span>
                                                
                                                <?php if ($available_slots > 0): ?>
                                                    <span class="badge badge-success">Available Now</span>
                                                <?php else: ?>
                                                    <?php if (!empty($predictions)): ?>
                                                        <span class="badge badge-warning">
                                                            Available <?php echo $predictions[0]['label']; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge badge-danger">Currently Full</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if ($available_slots == 0 && !empty($predictions)): ?>
                                                <div style="background: rgba(245, 158, 11, 0.05); border-radius: var(--radius-sm); padding: 10px; margin-bottom: 10px;">
                                                    <small style="color: var(--gray);">Next available:</small>
                                                    <div style="font-weight: 600; color: var(--warning);">
                                                        <?php echo $predictions[0]['label']; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div style="display: flex; gap: 10px;">
                                                <span class="badge badge-info">
                                                    <i class="fas fa-car"></i> <?php echo $row['total_slots']; ?> slots
                                                </span>
                                                
                                                <?php if (!empty($gallery)): ?>
                                                    <span class="badge badge-info">
                                                        <i class="fas fa-images"></i> <?php echo count($gallery); ?> photos
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-parking"></i>
                            <h3>No Parking Lots Found</h3>
                            <p>No parking lots found in <?php echo $user_city ? htmlspecialchars($user_city) : 'your area'; ?>.</p>
                            <button class="btn btn-primary" onclick="openLocationModal()">
                                <i class="fas fa-map-marker-alt"></i> Change Location
                            </button>
                        </div>
                    <?php endif; ?>
                    <?php $stmt->close(); ?>
                    
                <?php
                // VIEW: Location Details with Slots
                } elseif ($view == 'details' && $location_id) {
                    // Get location details
                    $query = "SELECT pl.*, u.username as manager_name 
                             FROM parking_locations pl
                             LEFT JOIN users u ON pl.admin_id = u.id
                             WHERE pl.id = ?";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("i", $location_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $location = $result->fetch_assoc();
                    
                    if (!$location) {
                        echo '<div class="alert alert-danger">Location not found.</div>';
                        echo '<a href="?view=list" class="btn btn-primary">Back to Parking Lots</a>';
                    } else {
                        // Get gallery images
                        $gallery = !empty($location['gallery_images']) ? explode(',', $location['gallery_images']) : [];
                        $main_image = !empty($location['image_path']) && file_exists($location['image_path']) ? $location['image_path'] : (isset($gallery[0]) && file_exists($gallery[0]) ? $gallery[0] : null);
                        
                        // Get all slots with their availability status
                        $slots_query = "SELECT ps.*, 
                                       (SELECT COUNT(*) FROM bookings b 
                                        WHERE b.slot_id = ps.id 
                                        AND b.end_time > NOW() 
                                        AND b.payment_status != 'cancelled') as future_bookings,
                                       (SELECT MIN(b.end_time) FROM bookings b 
                                        WHERE b.slot_id = ps.id 
                                        AND b.end_time > NOW() 
                                        AND b.payment_status != 'cancelled') as next_available
                                       FROM parking_slots ps
                                       WHERE ps.location_id = ?
                                       ORDER BY ps.slot_number";
                        $slots_stmt = $conn->prepare($slots_query);
                        $slots_stmt->bind_param("i", $location_id);
                        $slots_stmt->execute();
                        $slots_result = $slots_stmt->get_result();
                        ?>
                        
                        <div class="section-header">
                            <h2 class="section-title">
                                <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($location['name']); ?>
                            </h2>
                            <a href="?view=list" class="view-all">
                                <i class="fas fa-arrow-left"></i> Back to List
                            </a>
                        </div>
                        
                        <!-- Image Gallery -->
                        <div class="parking-card">
                            <div style="padding: 20px;">
                                <?php if ($main_image): ?>
                                    <div style="border-radius: var(--radius); overflow: hidden; height: 300px; margin-bottom: 15px;">
                                        <img src="<?php echo htmlspecialchars($main_image); ?>" 
                                             alt="<?php echo htmlspecialchars($location['name']); ?>" 
                                             id="main-location-image"
                                             style="width: 100%; height: 100%; object-fit: cover;">
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($gallery)): ?>
                                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 10px;">
                                        <?php foreach($gallery as $index => $image): ?>
                                            <?php if (file_exists($image)): ?>
                                                <div style="cursor: pointer; border-radius: var(--radius-sm); overflow: hidden; height: 60px; border: 2px solid transparent; transition: var(--transition);"
                                                     onclick="document.getElementById('main-location-image').src='<?php echo $image; ?>'">
                                                    <img src="<?php echo $image; ?>" alt="Gallery" style="width: 100%; height: 100%; object-fit: cover;">
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Location Info -->
                        <div class="parking-card">
                            <div style="padding: 25px;">
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                                    <div>
                                        <h4 style="font-size: 1rem; font-weight: 600; color: var(--dark); margin-bottom: 10px;">
                                            <i class="fas fa-info-circle"></i> Address
                                        </h4>
                                        <p style="color: var(--gray);">
                                            <?php echo htmlspecialchars($location['address']); ?><br>
                                            <?php echo htmlspecialchars($location['city']); ?>
                                        </p>
                                    </div>
                                    
                                    <div>
                                        <h4 style="font-size: 1rem; font-weight: 600; color: var(--dark); margin-bottom: 10px;">
                                            <i class="fas fa-dollar-sign"></i> Pricing
                                        </h4>
                                        <p style="color: var(--gray);">
                                            <span class="price-display">UGX<?php echo number_format($location['price_per_hour']); ?></span><small>/hour</small>
                                        </p>
                                    </div>
                                    
                                    <?php if ($location['contact_phone'] || $location['contact_email']): ?>
                                    <div>
                                        <h4 style="font-size: 1rem; font-weight: 600; color: var(--dark); margin-bottom: 10px;">
                                            <i class="fas fa-phone"></i> Contact
                                        </h4>
                                        <p style="color: var(--gray);">
                                            <?php if ($location['contact_phone']): ?>
                                                <i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars($location['contact_phone']); ?><br>
                                            <?php endif; ?>
                                            <?php if ($location['contact_email']): ?>
                                                <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($location['contact_email']); ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($location['operating_hours']): ?>
                                    <div>
                                        <h4 style="font-size: 1rem; font-weight: 600; color: var(--dark); margin-bottom: 10px;">
                                            <i class="fas fa-clock"></i> Operating Hours
                                        </h4>
                                        <p style="color: var(--gray);"><?php echo htmlspecialchars($location['operating_hours']); ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($location['amenities']): ?>
                                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--gray-light);">
                                    <h4 style="font-size: 1rem; font-weight: 600; color: var(--dark); margin-bottom: 10px;">
                                        <i class="fas fa-tools"></i> Amenities
                                    </h4>
                                    <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                                        <?php 
                                        $amenities = explode(',', $location['amenities']);
                                        foreach($amenities as $amenity): 
                                        ?>
                                            <span class="badge badge-info"><?php echo trim($amenity); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Available Slots -->
                        <div class="section-header" style="margin-bottom: 20px;">
                            <h3 class="section-title" style="font-size: 1.4rem;">
                                <i class="fas fa-parking"></i> Available Parking Slots
                            </h3>
                        </div>
                        
                        <?php if ($slots_result->num_rows > 0): ?>
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
                                <?php while($slot = $slots_result->fetch_assoc()): 
                                    $is_available = ($slot['status'] == 'available' && $slot['future_bookings'] == 0);
                                    $next_available_time = $slot['next_available'];
                                ?>
                                    <div class="parking-card" style="<?php echo !$is_available ? 'opacity: 0.8;' : ''; ?>">
                                        <div style="padding: 20px;">
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                                <h4 style="font-size: 1.2rem; font-weight: 700; color: var(--primary);">
                                                    Slot #<?php echo htmlspecialchars($slot['slot_number']); ?>
                                                </h4>
                                                <span class="badge <?php echo $is_available ? 'badge-success' : 'badge-warning'; ?>">
                                                    <?php 
                                                    if ($is_available) {
                                                        echo 'Available Now';
                                                    } elseif ($next_available_time) {
                                                        echo 'Available ' . getTimeDifferenceLabel($next_available_time);
                                                    } else {
                                                        echo 'Currently Occupied';
                                                    }
                                                    ?>
                                                </span>
                                            </div>
                                            
                                            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                                                <span class="badge badge-info">
                                                    <i class="fas fa-tag"></i> <?php echo ucfirst($slot['slot_type']); ?>
                                                </span>
                                                <span class="badge badge-info">
                                                    UGX<?php echo number_format($location['price_per_hour']); ?>/hr
                                                </span>
                                            </div>
                                            
                                            <?php if ($is_available): ?>
                                                <a href="?view=book&location_id=<?php echo $location_id; ?>&slot_id=<?php echo $slot['id']; ?>" 
                                                   class="btn btn-primary" style="width: 100%;">
                                                    <i class="fas fa-calendar-check"></i> Book This Slot
                                                </a>
                                            <?php elseif ($next_available_time): ?>
                                                <div style="background: rgba(245, 158, 11, 0.05); border-radius: var(--radius-sm); padding: 10px; margin-bottom: 10px; text-align: center;">
                                                    <small style="color: var(--gray);">Will be available</small>
                                                    <div style="font-weight: 600; color: var(--warning);">
                                                        <?php echo date('M d, h:i A', strtotime($next_available_time)); ?>
                                                    </div>
                                                </div>
                                                <a href="?view=book&location_id=<?php echo $location_id; ?>&slot_id=<?php echo $slot['id']; ?>" 
                                                   class="btn btn-outline-secondary" style="width: 100%;">
                                                    <i class="fas fa-calendar-alt"></i> Schedule Booking
                                                </a>
                                            <?php else: ?>
                                                <button class="btn btn-outline-secondary" style="width: 100%;" disabled>
                                                    <i class="fas fa-times-circle"></i> Currently Unavailable
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-parking"></i>
                                <h3>No Slots Available</h3>
                                <p>This parking location currently has no available slots.</p>
                                <a href="?view=list" class="btn btn-primary">
                                    <i class="fas fa-arrow-left"></i> Browse Other Locations
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <?php 
                        $slots_stmt->close();
                    }
                    $stmt->close();
                    
                // VIEW: Booking Form
                } elseif ($view == 'book' && isset($_GET['location_id']) && isset($_GET['slot_id'])) {
                    $location_id = intval($_GET['location_id']);
                    $slot_id = intval($_GET['slot_id']);
                    
                    // Get location and slot details
                    $query = "SELECT pl.*, ps.slot_number, ps.slot_type, ps.status 
                             FROM parking_locations pl
                             JOIN parking_slots ps ON pl.id = ps.location_id
                             WHERE pl.id = ? AND ps.id = ?";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("ii", $location_id, $slot_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $details = $result->fetch_assoc();
                    
                    if (!$details) {
                        echo '<div class="alert alert-danger">Slot not found.</div>';
                        echo '<a href="?view=list" class="btn btn-primary">Back to Parking Lots</a>';
                    } else {
                        ?>
                        
                        <div class="section-header">
                            <h2 class="section-title">
                                <i class="fas fa-calendar-check"></i> Book Parking Slot
                            </h2>
                            <a href="?view=details&location_id=<?php echo $location_id; ?>" class="view-all">
                                <i class="fas fa-arrow-left"></i> Back to Slots
                            </a>
                        </div>
                        
                        <div class="row" style="display: grid; grid-template-columns: 2fr 1fr; gap: 25px;">
                            <!-- Booking Form -->
                            <div class="parking-card">
                                <div style="padding: 30px;">
                                    <h3 style="font-size: 1.3rem; font-weight: 700; color: var(--dark); margin-bottom: 20px;">
                                        Booking Details
                                    </h3>
                                    
                                    <form method="POST" action="actions/create_scheduled_booking.php" id="booking-form">
                                        <input type="hidden" name="location_id" value="<?php echo $location_id; ?>">
                                        <input type="hidden" name="slot_id" value="<?php echo $slot_id; ?>">
                                        
                                        <div style="margin-bottom: 20px;">
                                            <label class="form-label">Vehicle Registration Number</label>
                                            <input type="text" name="vehicle_number" class="form-control" required 
                                                   placeholder="e.g., ABC-1234" maxlength="20">
                                        </div>
                                        
                                        <div style="margin-bottom: 20px;">
                                            <label class="form-label">Arrival Date & Time</label>
                                            <input type="datetime-local" name="start_time" class="form-control" required 
                                                   id="start-time" min="<?php echo date('Y-m-d\TH:i'); ?>">
                                        </div>
                                        
                                        <div style="margin-bottom: 20px;">
                                            <label class="form-label">Parking Duration (hours)</label>
                                            <select name="duration_hours" class="form-select" id="duration" required>
                                                <option value="">Select duration</option>
                                                <option value="1">1 hour</option>
                                                <option value="2">2 hours</option>
                                                <option value="3">3 hours</option>
                                                <option value="4">4 hours</option>
                                                <option value="6">6 hours</option>
                                                <option value="8">8 hours</option>
                                                <option value="12">12 hours</option>
                                                <option value="24">24 hours</option>
                                            </select>
                                        </div>
                                        
                                        <div style="margin-bottom: 20px; background: var(--light); border-radius: var(--radius); padding: 15px;">
                                            <h4 style="font-size: 1rem; font-weight: 600; color: var(--dark); margin-bottom: 10px;">
                                                Price Breakdown
                                            </h4>
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                                <span style="color: var(--gray);">Hourly Rate:</span>
                                                <span style="font-weight: 600;">UGX<?php echo number_format($details['price_per_hour']); ?></span>
                                            </div>
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                                <span style="color: var(--gray);">Duration:</span>
                                                <span style="font-weight: 600;" id="duration-display">-</span>
                                            </div>
                                            <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 10px; border-top: 1px solid var(--gray-light);">
                                                <span style="font-weight: 700; color: var(--dark);">Total:</span>
                                                <span style="font-size: 1.3rem; font-weight: 700; color: var(--primary);" id="total-price">UGX0</span>
                                            </div>
                                        </div>
                                        
                                        <div class="alert alert-warning">
                                            <i class="fas fa-info-circle"></i>
                                            <strong>Note:</strong> You will pay after your parking session ends. 
                                            Please arrive on time to secure your slot.
                                        </div>
                                        
                                        <button type="submit" class="btn btn-success" style="width: 100%; padding: 15px; font-size: 1.1rem;">
                                            <i class="fas fa-check-circle"></i> Confirm Booking
                                        </button>
                                    </form>
                                </div>
                            </div>
                            
                            <!-- Slot Summary -->
                            <div>
                                <div class="parking-card" style="position: sticky; top: 100px;">
                                    <div style="padding: 25px;">
                                        <h4 style="font-size: 1.1rem; font-weight: 700; color: var(--dark); margin-bottom: 20px;">
                                            <i class="fas fa-parking"></i> Selected Slot
                                        </h4>
                                        
                                        <div style="text-align: center; margin-bottom: 20px;">
                                            <div style="font-size: 2rem; font-weight: 800; color: var(--primary); margin-bottom: 10px;">
                                                #<?php echo htmlspecialchars($details['slot_number']); ?>
                                            </div>
                                            <span class="badge badge-info" style="font-size: 0.9rem; padding: 8px 15px;">
                                                <?php echo ucfirst($details['slot_type']); ?>
                                            </span>
                                        </div>
                                        
                                        <div style="margin-bottom: 20px; padding: 15px 0; border-top: 1px solid var(--gray-light); border-bottom: 1px solid var(--gray-light);">
                                            <div style="margin-bottom: 10px;">
                                                <span style="color: var(--gray);">Location:</span>
                                                <span style="font-weight: 600; color: var(--dark);"><?php echo htmlspecialchars($details['name']); ?></span>
                                            </div>
                                            <div>
                                                <span style="color: var(--gray);">Address:</span>
                                                <span style="font-weight: 600; color: var(--dark);"><?php echo htmlspecialchars($details['address']); ?></span>
                                            </div>
                                        </div>
                                        
                                        <div style="margin-bottom: 20px;">
                                            <h5 style="font-size: 1rem; font-weight: 600; color: var(--dark); margin-bottom: 10px;">
                                                <i class="fas fa-clock"></i> Important
                                            </h5>
                                            <ul style="padding-left: 20px; color: var(--gray);">
                                                <li style="margin-bottom: 5px;">You have 15 minutes grace period after your scheduled start time</li>
                                                <li style="margin-bottom: 5px;">You can extend your parking if no one has booked after you</li>
                                                <li>Payment is due immediately after parking ends</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            const startTime = document.getElementById('start-time');
                            const duration = document.getElementById('duration');
                            const durationDisplay = document.getElementById('duration-display');
                            const totalPrice = document.getElementById('total-price');
                            const pricePerHour = <?php echo $details['price_per_hour']; ?>;
                            
                            function updatePrice() {
                                if (startTime.value && duration.value) {
                                    const hours = parseInt(duration.value);
                                    durationDisplay.textContent = hours + ' hour' + (hours > 1 ? 's' : '');
                                    totalPrice.textContent = 'UGX' + (hours * pricePerHour).toLocaleString();
                                } else {
                                    durationDisplay.textContent = '-';
                                    totalPrice.textContent = 'UGX0';
                                }
                            }
                            
                            startTime.addEventListener('change', updatePrice);
                            duration.addEventListener('change', updatePrice);
                            
                            // Set minimum date to now
                            const now = new Date();
                            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                            startTime.min = now.toISOString().slice(0, 16);
                        });
                        </script>
                        
                        <?php 
                    }
                    $stmt->close();
                    
                // VIEW: Active Booking / Extension
                } elseif ($view == 'active' && $active_booking) {
                    $booking = $active_booking;
                    $time_remaining = strtotime($booking['end_time']) - time();
                    $hours_remaining = floor($time_remaining / 3600);
                    $minutes_remaining = floor(($time_remaining % 3600) / 60);
                    
                    // Check if extension is possible
                    $can_extend = true;
                    $extension_check = "SELECT COUNT(*) as future_booking FROM bookings 
                                       WHERE slot_id = ? 
                                       AND start_time > ? 
                                       AND start_time < DATE_ADD(?, INTERVAL 24 HOUR)
                                       AND payment_status != 'cancelled'";
                    $ext_stmt = $conn->prepare($extension_check);
                    $ext_stmt->bind_param("iss", $booking['slot_id'], $booking['end_time'], $booking['end_time']);
                    $ext_stmt->execute();
                    $ext_result = $ext_stmt->get_result();
                    $ext_row = $ext_result->fetch_assoc();
                    if ($ext_row['future_booking'] > 0) {
                        $can_extend = false;
                    }
                    $ext_stmt->close();
                    ?>
                    
                    <div class="section-header">
                        <h2 class="section-title">
                            <i class="fas fa-car"></i> Active Parking Session
                        </h2>
                    </div>
                    
                    <div class="parking-card" style="border-color: var(--success);">
                        <div style="padding: 30px;">
                            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 30px;">
                                <div>
                                    <h3 style="font-size: 1.3rem; font-weight: 700; color: var(--dark); margin-bottom: 15px;">
                                        <?php echo htmlspecialchars($booking['location_name']); ?>
                                    </h3>
                                    <p style="color: var(--gray); margin-bottom: 20px;">
                                        <i class="fas fa-map-marker-alt" style="color: var(--primary);"></i> 
                                        <?php echo htmlspecialchars($booking['address']); ?><br>
                                        <i class="fas fa-hashtag" style="color: var(--primary);"></i> 
                                        Slot #<?php echo htmlspecialchars($booking['slot_number']); ?>
                                    </p>
                                    
                                    <div style="background: var(--light); border-radius: var(--radius); padding: 20px; margin-bottom: 20px;">
                                        <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                                            <span style="color: var(--gray);">Start Time:</span>
                                            <span style="font-weight: 600;"><?php echo date('M d, Y h:i A', strtotime($booking['start_time'])); ?></span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                                            <span style="color: var(--gray);">End Time:</span>
                                            <span style="font-weight: 600;"><?php echo date('M d, Y h:i A', strtotime($booking['end_time'])); ?></span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between;">
                                            <span style="color: var(--gray);">Time Remaining:</span>
                                            <span style="font-weight: 700; color: var(--primary);" id="timer-display">
                                                <?php echo $hours_remaining; ?>h <?php echo $minutes_remaining; ?>m
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <?php if ($can_extend && $time_remaining > 0): ?>
                                        <div class="parking-card" style="margin-bottom: 20px;">
                                            <div style="padding: 20px;">
                                                <h4 style="font-size: 1rem; font-weight: 600; color: var(--dark); margin-bottom: 15px;">
                                                    <i class="fas fa-clock"></i> Extend Parking Time
                                                </h4>
                                                
                                                <form method="POST" action="">
                                                    <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                    <input type="hidden" name="extend_booking" value="1">
                                                    
                                                    <div style="margin-bottom: 15px;">
                                                        <select name="additional_hours" class="form-select" required>
                                                            <option value="">Select extension time</option>
                                                            <option value="1">1 hour (+UGX<?php echo number_format($booking['price_per_hour']); ?>)</option>
                                                            <option value="2">2 hours (+UGX<?php echo number_format($booking['price_per_hour'] * 2); ?>)</option>
                                                            <option value="3">3 hours (+UGX<?php echo number_format($booking['price_per_hour'] * 3); ?>)</option>
                                                            <option value="4">4 hours (+UGX<?php echo number_format($booking['price_per_hour'] * 4); ?>)</option>
                                                            <option value="6">6 hours (+UGX<?php echo number_format($booking['price_per_hour'] * 6); ?>)</option>
                                                        </select>
                                                    </div>
                                                    
                                                    <button type="submit" class="btn btn-outline" style="width: 100%;">
                                                        <i class="fas fa-plus-circle"></i> Extend Parking
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($time_remaining <= 0): ?>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            Your parking time has ended. Please make payment.
                                        </div>
                                        
                                        <form method="POST" action="">
                                            <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                            <input type="hidden" name="end_and_pay" value="1">
                                            <button type="submit" class="btn btn-success" style="width: 100%; padding: 15px;">
                                                <i class="fas fa-credit-card"></i> Pay UGX<?php echo number_format($booking['total_cost']); ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                
                                <div>
                                    <div class="parking-card" style="background: var(--light);">
                                        <div style="padding: 25px;">
                                            <h4 style="font-size: 1rem; font-weight: 600; color: var(--dark); margin-bottom: 20px;">
                                                Payment Summary
                                            </h4>
                                            
                                            <div style="margin-bottom: 20px;">
                                                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                                    <span style="color: var(--gray);">Hourly Rate:</span>
                                                    <span>UGX<?php echo number_format($booking['price_per_hour']); ?></span>
                                                </div>
                                                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                                    <span style="color: var(--gray);">Duration:</span>
                                                    <span>
                                                        <?php 
                                                        $hours = ceil((strtotime($booking['end_time']) - strtotime($booking['start_time'])) / 3600);
                                                        echo $hours . ' hour' . ($hours > 1 ? 's' : '');
                                                        ?>
                                                    </span>
                                                </div>
                                                <div style="display: flex; justify-content: space-between; padding-top: 10px; border-top: 1px solid var(--gray-light);">
                                                    <span style="font-weight: 700;">Total:</span>
                                                    <span style="font-size: 1.2rem; font-weight: 700; color: var(--primary);">
                                                        UGX<?php echo number_format($booking['total_cost']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            
                                            <?php if ($time_remaining > 0): ?>
                                                <div class="timer" style="color: var(--warning);" id="countdown-timer"></div>
                                                <p style="text-align: center; color: var(--gray); font-size: 0.85rem; margin-top: 10px;">
                                                    Time remaining until payment is due
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <script>
                    <?php if ($time_remaining > 0): ?>
                    // Countdown timer
                    let endTime = new Date("<?php echo $booking['end_time']; ?>").getTime();
                    
                    function updateCountdown() {
                        const now = new Date().getTime();
                        const distance = endTime - now;
                        
                        if (distance < 0) {
                            document.getElementById('countdown-timer').innerHTML = "TIME EXPIRED";
                            document.getElementById('timer-display').innerHTML = "0h 0m";
                            location.reload(); // Refresh to show payment option
                            return;
                        }
                        
                        const hours = Math.floor(distance / (1000 * 60 * 60));
                        const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                        const seconds = Math.floor((distance % (1000 * 60)) / 1000);
                        
                        document.getElementById('countdown-timer').innerHTML = 
                            hours.toString().padStart(2, '0') + ":" + 
                            minutes.toString().padStart(2, '0') + ":" + 
                            seconds.toString().padStart(2, '0');
                        
                        document.getElementById('timer-display').innerHTML = 
                            hours + "h " + minutes + "m";
                        
                        setTimeout(updateCountdown, 1000);
                    }
                    
                    updateCountdown();
                    <?php endif; ?>
                    </script>
                    
                <?php
                // VIEW: No active booking
                } else {
                    echo '<div class="empty-state">';
                    echo '<i class="fas fa-car"></i>';
                    echo '<h3>No Active Parking Session</h3>';
                    echo '<p>You don\'t have any active parking sessions.</p>';
                    echo '<a href="?view=list" class="btn btn-primary">';
                    echo '<i class="fas fa-search"></i> Find Parking';
                    echo '</a>';
                    echo '</div>';
                }
                ?>
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
                <form method="GET" action="find_parking.php">
                    <input type="hidden" name="view" value="<?php echo $view; ?>">
                    <?php if ($location_id): ?>
                    <input type="hidden" name="location_id" value="<?php echo $location_id; ?>">
                    <?php endif; ?>
                    
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label for="cityInput" class="form-label">City</label>
                        <input type="text" id="cityInput" name="city" class="form-control" 
                               value="<?php echo htmlspecialchars($user_city); ?>" 
                               placeholder="Enter your city" required>
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
    // Modal functions
    function openLocationModal() {
        document.getElementById('locationModal').style.display = 'flex';
    }
    
    function closeLocationModal() {
        document.getElementById('locationModal').style.display = 'none';
    }
    
    // Close modal when clicking outside
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
                // For demo purposes, we'll use a reverse geocoding service
                // In production, you'd want to use a proper geocoding API
                alert('Location detected! Please enter your city manually for now.');
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
    
    <?php
    // Close database connection
    if ($conn) {
        $conn->close();
    }
    ?>
</body>
</html>