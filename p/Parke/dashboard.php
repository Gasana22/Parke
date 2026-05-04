<?php
// dashboard.php - Modern User-Friendly Dashboard
require_once 'includes/functions.php';

// Require user to be logged in
require_login();

$user = get_logged_in_user();

// Function to get profile picture path
function get_profile_picture_path($user) {
    if (!empty($user['picture']) && file_exists('uploads/profiles/' . $user['picture'])) {
        return 'uploads/profiles/' . $user['picture'];
    }
    return null;
}

$profile_picture = get_profile_picture_path($user);

// Get user's current location
$user_city = $_SESSION['user_city'] ?? $_GET['city'] ?? '';
$user_lat = $_SESSION['user_lat'] ?? $_GET['lat'] ?? null;
$user_lng = $_SESSION['user_lng'] ?? $_GET['lng'] ?? null;

// Update location if provided
if (isset($_GET['city']) && $_GET['city']) {
    $_SESSION['user_city'] = $_GET['city'];
    $user_city = $_GET['city'];
}

$conn = get_db_connection();

// Get active bookings (current and upcoming) with no-show detection
$active_stmt = $conn->prepare("
    SELECT b.*, 
           pl.name as location_name, 
           pl.address, 
           pl.city,
           pl.price_per_hour, 
           ps.slot_number, 
           ps.slot_type,
           TIMESTAMPDIFF(MINUTE, NOW(), b.end_time) as minutes_remaining,
           TIMESTAMPDIFF(MINUTE, b.start_time, NOW()) as minutes_since_start,
           CASE 
               WHEN NOW() BETWEEN b.start_time AND b.end_time THEN 'active'
               WHEN NOW() < b.start_time THEN 'upcoming'
               WHEN NOW() > b.start_time AND NOW() < DATE_ADD(b.start_time, INTERVAL 10 MINUTE) THEN 'grace_period'
               WHEN NOW() > DATE_ADD(b.start_time, INTERVAL 10 MINUTE) AND b.payment_status = 'pending' THEN 'no_show'
               ELSE 'expired'
           END as session_status
    FROM bookings b
    JOIN parking_locations pl ON b.location_id = pl.id
    JOIN parking_slots ps ON b.slot_id = ps.id
    WHERE b.user_id = ? 
    AND b.payment_status != 'cancelled'
    AND (b.end_time > NOW() OR (b.payment_status = 'pending' AND b.end_time <= NOW()))
    ORDER BY 
        CASE 
            WHEN NOW() BETWEEN b.start_time AND b.end_time THEN 1
            WHEN NOW() < b.start_time THEN 2
            WHEN NOW() > b.start_time AND NOW() < DATE_ADD(b.start_time, INTERVAL 10 MINUTE) THEN 3
            ELSE 4
        END,
        b.start_time ASC
    LIMIT 3
");
$active_stmt->bind_param("i", $user['id']);
$active_stmt->execute();
$active_bookings = $active_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get nearby parking with availability
$nearby_parking = [];
if ($user_city) {
    $parking_stmt = $conn->prepare("
        SELECT pl.*, 
               COUNT(ps.id) as total_slots,
               SUM(CASE WHEN ps.status = 'available' THEN 1 ELSE 0 END) as available_slots
        FROM parking_locations pl
        LEFT JOIN parking_slots ps ON pl.id = ps.location_id
        WHERE pl.city = ? 
        AND pl.status = 'active'
        GROUP BY pl.id
        HAVING available_slots > 0
        ORDER BY pl.name
        LIMIT 6
    ");
    $parking_stmt->bind_param("s", $user_city);
    $parking_stmt->execute();
    $nearby_parking = $parking_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get recent history (completed and cancelled bookings)
$history_stmt = $conn->prepare("
    SELECT b.*, 
           pl.name as location_name, 
           pl.address,
           TIMESTAMPDIFF(MINUTE, b.start_time, b.end_time) as duration_minutes,
           CASE 
               WHEN b.payment_status = 'cancelled' AND b.end_time IS NOT NULL AND b.start_time < NOW() THEN 'no_show_cancelled'
               ELSE b.payment_status
           END as display_status
    FROM bookings b
    JOIN parking_locations pl ON b.location_id = pl.id
    WHERE b.user_id = ? 
    AND (b.payment_status = 'paid' OR b.payment_status = 'cancelled' OR b.end_time <= NOW())
    ORDER BY b.start_time DESC
    LIMIT 4
");
$history_stmt->bind_param("i", $user['id']);
$history_stmt->execute();
$recent_history = $history_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate stats
$stats = [
    'active_bookings' => count(array_filter($active_bookings, function($b) {
        return $b['session_status'] == 'active' || $b['session_status'] == 'grace_period';
    })),
    'total_bookings' => 0,
    'total_spent' => 0,
    'favorite_city' => $user_city ?: 'Not set',
    'no_show_warning' => count(array_filter($active_bookings, function($b) {
        return $b['session_status'] == 'no_show';
    }))
];

$stats_stmt = $conn->prepare("
    SELECT COUNT(*) as total, SUM(total_cost) as spent
    FROM bookings 
    WHERE user_id = ? AND payment_status = 'paid'
");
$stats_stmt->bind_param("i", $user['id']);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result()->fetch_assoc();
$stats['total_bookings'] = $stats_result['total'] ?? 0;
$stats['total_spent'] = $stats_result['spent'] ?? 0;

// Handle end booking request
if (isset($_GET['end_booking'])) {
    $booking_id = intval($_GET['end_booking']);
    
    // Get booking details to calculate final cost
    $booking_query = "SELECT b.*, pl.price_per_hour 
                     FROM bookings b
                     JOIN parking_locations pl ON b.location_id = pl.id
                     WHERE b.id = ? AND b.user_id = ?";
    $booking_stmt = $conn->prepare($booking_query);
    $booking_stmt->bind_param("ii", $booking_id, $user['id']);
    $booking_stmt->execute();
    $booking = $booking_stmt->get_result()->fetch_assoc();
    
    if ($booking) {
        // Calculate final cost based on actual time used
        $now = time();
        $start = strtotime($booking['start_time']);
        $hours_used = ceil(($now - $start) / 3600); // Round up to nearest hour
        $final_cost = max(1, $hours_used) * $booking['price_per_hour'];
        
        // Update booking to mark as ended (payment pending)
        $update = "UPDATE bookings SET end_time = NOW(), total_cost = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update);
        $update_stmt->bind_param("di", $final_cost, $booking_id);
        
        if ($update_stmt->execute()) {
            // Update slot status to available
            $slot_update = "UPDATE parking_slots SET status = 'available', current_vehicle = NULL WHERE id = ?";
            $slot_stmt = $conn->prepare($slot_update);
            $slot_stmt->bind_param("i", $booking['slot_id']);
            $slot_stmt->execute();
            
            set_message("success", "Parking session ended successfully. Please complete payment.");
        } else {
            set_message("error", "Failed to end parking session.");
        }
    }
    
    header("Location: dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Parke</title>
    <style>
        /* All existing CSS styles remain the same */
        :root {
            --primary: #2563eb;
            --primary-light: #3b82f6;
            --primary-dark: #1d4ed8;
            --primary-soft: #dbeafe;
            --secondary: #0ea5e9;
            --accent: #8b5cf6;
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
            --gradient-primary-reverse: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%);
            --gradient-light: linear-gradient(135deg, #f0f9ff 0%, #e0f7ff 100%);
            --gradient-card: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            --gradient-avatar: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --shadow-sm: 0 1px 3px rgba(30, 41, 59, 0.1);
            --shadow: 0 4px 6px -1px rgba(30, 41, 59, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(30, 41, 59, 0.1);
            --shadow-xl: 0 20px 25px -5px rgba(30, 41, 59, 0.1);
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
            line-height: 1.5;
            font-weight: 400;
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
            padding: 10px 0;
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
            transition: var(--transition);
        }
        
        .logo:hover {
            color: var(--primary);
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
            box-shadow: var(--shadow-primary);
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
            background: var(--gradient-avatar);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
            box-shadow: var(--shadow);
            transition: var(--transition);
        }
        
        .user-avatar:hover {
            transform: scale(1.05);
            box-shadow: var(--shadow-lg);
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
            transform: translateY(-2px);
            box-shadow: var(--shadow);
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
            box-shadow: var(--shadow-primary);
            transform: translateX(4px);
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
        }
        
        /* Welcome Card */
        .welcome-card {
            background: var(--gradient-primary);
            border-radius: var(--radius-lg);
            padding: 30px;
            color: white;
            box-shadow: var(--shadow-xl);
            position: relative;
            overflow: hidden;
        }
        
        .welcome-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
        }
        
        .welcome-content h1 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .welcome-content p {
            opacity: 0.9;
            margin-bottom: 25px;
            max-width: 600px;
        }
        
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .stat {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border-radius: var(--radius);
            padding: 20px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: var(--transition);
        }
        
        .stat:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-3px);
        }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.85rem;
            opacity: 0.9;
        }
        
        /* Section Headers */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .section-title {
            font-size: 1.4rem;
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
        
        /* Cards Grid */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }
        
        .card {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 25px;
            box-shadow: var(--shadow);
            transition: var(--transition);
            border: 1px solid var(--gray-light);
            background: var(--gradient-card);
            position: relative;
            overflow: hidden;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }
        
        .card::before {
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
        
        .card:hover::before {
            opacity: 1;
        }
        
        .card.active::before {
            background: var(--gradient-primary);
            opacity: 1;
        }
        
        .card.upcoming::before {
            background: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%);
            opacity: 1;
        }
        
        .card.grace::before {
            background: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%);
            opacity: 1;
        }
        
        .card.no-show::before {
            background: linear-gradient(135deg, var(--danger) 0%, #ff9800 100%);
            opacity: 1;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark);
        }
        
        .card-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .badge-upcoming {
            background: rgba(37, 99, 235, 0.1);
            color: var(--primary);
            border: 1px solid rgba(37, 99, 235, 0.2);
        }
        
        .badge-grace {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }
        
        .badge-no-show {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        
        .badge-pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }
        
        .parking-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 5px;
        }
        
        .parking-price span {
            font-size: 0.9rem;
            color: var(--gray);
            font-weight: 400;
        }
        
        .parking-info {
            color: var(--gray);
            font-size: 0.9rem;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .parking-info i {
            width: 16px;
            color: var(--primary);
        }
        
        .parking-stats {
            display: flex;
            gap: 20px;
            margin: 20px 0;
            padding: 15px 0;
            border-top: 1px solid var(--gray-light);
            border-bottom: 1px solid var(--gray-light);
        }
        
        .parking-stat {
            text-align: center;
            flex: 1;
        }
        
        .stat-count {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.8rem;
            color: var(--gray);
        }
        
        .timer {
            font-family: 'Courier New', monospace;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--warning);
            text-align: center;
            margin: 15px 0;
        }
        
        .timer-label {
            font-size: 0.8rem;
            color: var(--gray);
            text-align: center;
        }
        
        .grace-warning {
            background: #fef3c7;
            border: 1px solid var(--warning);
            border-radius: var(--radius);
            padding: 12px;
            margin: 15px 0;
            text-align: center;
        }
        
        .grace-warning i {
            color: var(--warning);
            margin-right: 8px;
        }
        
        .no-show-warning {
            background: #fee2e2;
            border: 1px solid var(--danger);
            border-radius: var(--radius);
            padding: 12px;
            margin: 15px 0;
            text-align: center;
        }
        
        .no-show-warning i {
            color: var(--danger);
            margin-right: 8px;
        }
        
        .card-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            border: none;
        }
        
        .btn-primary {
            background: var(--gradient-primary);
            color: white;
            flex: 1;
            box-shadow: var(--shadow-sm);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-primary);
        }
        
        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
            flex: 1;
        }
        
        .btn-outline:hover {
            background: var(--primary-soft);
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: var(--secondary);
            color: white;
            flex: 1;
            box-shadow: var(--shadow-sm);
        }
        
        .btn-secondary:hover {
            background: var(--secondary-light);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(14, 165, 233, 0.3);
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
            flex: 1;
        }
        
        .btn-danger:hover {
            background: #dc2626;
            transform: translateY(-2px);
        }
        
        .btn-warning {
            background: var(--warning);
            color: white;
            flex: 1;
        }
        
        .btn-warning:hover {
            background: #e07b0b;
            transform: translateY(-2px);
        }
        
        .btn-success {
            background: var(--success);
            color: white;
            flex: 1;
        }
        
        .btn-success:hover {
            background: #0da271;
            transform: translateY(-2px);
        }
        
        /* History List */
        .history-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .history-item {
            display: flex;
            align-items: center;
            padding: 20px;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            border: 1px solid var(--gray-light);
        }
        
        .history-item:hover {
            box-shadow: var(--shadow);
            border-color: var(--primary-light);
            transform: translateY(-2px);
        }
        
        .history-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            margin-right: 20px;
            flex-shrink: 0;
            box-shadow: var(--shadow);
        }
        
        .history-details {
            flex: 1;
        }
        
        .history-location {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .history-info {
            color: var(--gray);
            font-size: 0.9rem;
            margin-bottom: 5px;
        }
        
        .history-time {
            font-size: 0.8rem;
            color: var(--gray);
        }
        
        .history-cost {
            font-weight: 700;
            color: var(--primary);
            font-size: 1.1rem;
        }
        
        .history-status {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .status-paid {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .status-cancelled {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }
        
        .status-no-show {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }
        
        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-light);
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
        
        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #d1fae5;
            border: 1px solid var(--success);
            color: #065f46;
        }
        
        .alert-error {
            background: #fee2e2;
            border: 1px solid var(--danger);
            color: #991b1b;
        }
        
        .alert-warning {
            background: #fef3c7;
            border: 1px solid var(--warning);
            color: #92400e;
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
            box-shadow: var(--shadow-xl);
            border: 1px solid var(--gray-light);
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
            padding: 5px;
            transition: var(--transition);
        }
        
        .modal-close:hover {
            color: var(--dark);
            transform: rotate(90deg);
        }
        
        .modal-body {
            margin-bottom: 25px;
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
        
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--gray-light);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        /* Loading Animation */
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
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .dashboard-layout {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                position: static;
            }
            
            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .top-nav {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .user-menu {
                width: 100%;
                justify-content: center;
            }
            
            .cards-grid {
                grid-template-columns: 1fr;
            }
            
            .card-actions {
                flex-direction: column;
            }
            
            .welcome-content h1 {
                font-size: 1.5rem;
            }
            
            .quick-stats {
                grid-template-columns: 1fr;
            }
            
            .history-item {
                flex-direction: column;
                text-align: center;
            }
            
            .history-icon {
                margin-right: 0;
                margin-bottom: 15px;
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
                <a href="notifications.php" class="notification-btn">
                    <i class="fas fa-bell"></i>
                    <?php if ($stats['active_bookings'] > 0 || $stats['no_show_warning'] > 0): ?>
                        <span class="notification-badge"><?php echo $stats['active_bookings'] + $stats['no_show_warning']; ?></span>
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
        
        <!-- Main Dashboard Layout -->
        <div class="dashboard-layout">
            <!-- Sidebar -->
            <div class="sidebar">
                <ul class="sidebar-nav">
                    <li>
                        <a href="dashboard.php" class="active">
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
                        <a href="booking.php">
                            <i class="fas fa-calendar-alt"></i>
                            <span>My Bookings</span>
                            <?php if ($stats['active_bookings'] > 0): ?>
                                <span style="margin-left: auto; background: var(--primary); color: white; width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.7rem;">
                                    <?php echo $stats['active_bookings']; ?>
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
                <?php echo display_message(); ?>
                
                <!-- Welcome Card -->
                <div class="welcome-card">
                    <div class="welcome-content">
                        <h1>Welcome back, <?php echo htmlspecialchars($user['username']); ?>! 🚗</h1>
                        <p>Here's what's happening with your parking today</p>
                    </div>
                    
                    <div class="quick-stats">
                        <div class="stat">
                            <div class="stat-number"><?php echo $stats['active_bookings']; ?></div>
                            <div class="stat-label">Active Bookings</div>
                        </div>
                        <div class="stat">
                            <div class="stat-number"><?php echo $stats['total_bookings']; ?></div>
                            <div class="stat-label">Total Bookings</div>
                        </div>
                        <div class="stat">
                            <div class="stat-number">UGX<?php echo number_format($stats['total_spent'], 0); ?></div>
                            <div class="stat-label">Total Spent</div>
                        </div>
                        <div class="stat">
                            <div class="stat-number">
                                <?php echo $nearby_parking ? count($nearby_parking) : '0'; ?>
                            </div>
                            <div class="stat-label">Nearby Lots</div>
                        </div>
                    </div>
                </div>
                
                <!-- Active Bookings Section -->
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-clock"></i> Active Bookings
                    </h2>
                    <a href="booking.php" class="view-all">
                        View All <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <?php if (empty($active_bookings)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Active Bookings</h3>
                        <p>You don't have any active parking sessions right now.</p>
                        <a href="find_parking.php" class="btn btn-primary">
                            <i class="fas fa-search"></i> Find Parking
                        </a>
                    </div>
                <?php else: ?>
                    <div class="cards-grid">
                        <?php foreach ($active_bookings as $booking): 
                            $start_time = strtotime($booking['start_time']);
                            $end_time = strtotime($booking['end_time']);
                            $now = time();
                            $total_duration = ($end_time - $start_time) / 3600;
                            $elapsed = ($now - $start_time) / 3600;
                            $remaining = ($end_time - $now) / 60;
                            $minutes_late = $booking['minutes_since_start'];
                            
                            $is_active = ($booking['session_status'] == 'active');
                            $is_upcoming = ($booking['session_status'] == 'upcoming');
                            $is_grace = ($booking['session_status'] == 'grace_period');
                            $is_no_show = ($booking['session_status'] == 'no_show');
                            
                            $card_class = $is_active ? 'active' : ($is_upcoming ? 'upcoming' : ($is_grace ? 'grace' : ($is_no_show ? 'no-show' : '')));
                            $badge_class = $is_active ? 'badge-active' : ($is_upcoming ? 'badge-upcoming' : ($is_grace ? 'badge-grace' : 'badge-no-show'));
                            $badge_text = $is_active ? '<i class="fas fa-play-circle"></i> Active Now' : 
                                          ($is_upcoming ? '<i class="fas fa-clock"></i> Upcoming' : 
                                          ($is_grace ? '<i class="fas fa-hourglass-start"></i> Grace Period' : 
                                          '<i class="fas fa-exclamation-triangle"></i> No-Show Warning'));
                        ?>
                            <div class="card <?php echo $card_class; ?>" id="booking-<?php echo $booking['id']; ?>">
                                <div class="card-header">
                                    <h3 class="card-title"><?php echo htmlspecialchars($booking['location_name']); ?></h3>
                                    <span class="card-badge <?php echo $badge_class; ?>">
                                        <?php echo $badge_text; ?>
                                    </span>
                                </div>
                                
                                <div class="parking-price">
                                    UGX<?php echo number_format($booking['price_per_hour'], 0); ?>
                                    <span>/hour</span>
                                </div>
                                
                                <div class="parking-info">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($booking['address']); ?>
                                </div>
                                
                                <div class="parking-info">
                                    <i class="fas fa-car"></i>
                                    Vehicle: <?php echo htmlspecialchars($booking['vehicle_number']); ?>
                                </div>
                                
                                <div class="parking-info">
                                    <i class="fas fa-parking"></i>
                                    Slot #<?php echo htmlspecialchars($booking['slot_number']); ?> (<?php echo ucfirst($booking['slot_type']); ?>)
                                </div>
                                
                                <?php if ($is_grace): ?>
                                    <div class="grace-warning">
                                        <i class="fas fa-hourglass-half"></i>
                                        <strong>Grace Period Active!</strong><br>
                                        You have <?php echo 10 - $minutes_late; ?> minutes left to check in before your booking is cancelled.
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($is_no_show): ?>
                                    <div class="no-show-warning">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <strong>Booking Expired!</strong><br>
                                        You missed your booking time by <?php echo $minutes_late; ?> minutes. The slot may have been made available to others.
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($is_active): ?>
                                    <div class="timer" id="timer-<?php echo $booking['id']; ?>">
                                        <?php 
                                            $hours = floor($remaining / 60);
                                            $minutes = floor($remaining % 60);
                                            echo sprintf("%02d:%02d:%02d", $hours, $minutes, 0);
                                        ?>
                                    </div>
                                    <div class="timer-label">Time Remaining</div>
                                <?php elseif ($is_upcoming): ?>
                                    <div class="parking-stats">
                                        <div class="parking-stat">
                                            <div class="stat-count"><?php echo date('M d', $start_time); ?></div>
                                            <div class="stat-label">Date</div>
                                        </div>
                                        <div class="parking-stat">
                                            <div class="stat-count"><?php echo date('h:i A', $start_time); ?></div>
                                            <div class="stat-label">Start Time</div>
                                        </div>
                                        <div class="parking-stat">
                                            <div class="stat-count"><?php echo ceil($total_duration); ?>h</div>
                                            <div class="stat-label">Duration</div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="parking-stats" style="margin-top: 0; border-top: none;">
                                    <div class="parking-stat">
                                        <div class="stat-count"><?php echo number_format($booking['total_cost'], 0); ?></div>
                                        <div class="stat-label">Est. Cost</div>
                                    </div>
                                    <div class="parking-stat">
                                        <div class="stat-count">
                                            <?php if ($is_active): ?>
                                                <?php echo number_format($elapsed * $booking['price_per_hour'], 0); ?>
                                            <?php else: ?>
                                                --
                                            <?php endif; ?>
                                        </div>
                                        <div class="stat-label">Current</div>
                                    </div>
                                </div>
                                
                                <div class="card-actions">
                                    <?php if ($is_active): ?>
                                        <a href="?end_booking=<?php echo $booking['id']; ?>" 
                                           class="btn btn-warning"
                                           onclick="return confirm('Are you sure you want to end this parking session?')">
                                            <i class="fas fa-stop-circle"></i> End Now
                                        </a>
                                        <a href="booking.php?extend=<?php echo $booking['id']; ?>" class="btn btn-outline">
                                            <i class="fas fa-clock"></i> Extend
                                        </a>
                                    <?php elseif ($is_upcoming): ?>
                                        <a href="booking.php?cancel=<?php echo $booking['id']; ?>" 
                                           class="btn btn-danger"
                                           onclick="return confirm('Are you sure you want to cancel this booking?')">
                                            <i class="fas fa-times-circle"></i> Cancel
                                        </a>
                                        <a href="find_parking.php?location_id=<?php echo $booking['location_id']; ?>" class="btn btn-outline">
                                            <i class="fas fa-directions"></i> Directions
                                        </a>
                                    <?php elseif ($is_grace): ?>
                                        <a href="find_parking.php?location_id=<?php echo $booking['location_id']; ?>&slot_id=<?php echo $booking['slot_id']; ?>" 
                                           class="btn btn-primary">
                                            <i class="fas fa-check-circle"></i> Check In Now
                                        </a>
                                        <a href="booking.php?cancel=<?php echo $booking['id']; ?>" 
                                           class="btn btn-outline"
                                           onclick="return confirm('Are you sure you want to cancel this booking?')">
                                            <i class="fas fa-times"></i> Cancel
                                        </a>
                                    <?php elseif ($is_no_show): ?>
                                        <a href="find_parking.php" class="btn btn-primary">
                                            <i class="fas fa-search"></i> Find New Parking
                                        </a>
                                        <a href="booking.php?history" class="btn btn-outline">
                                            <i class="fas fa-history"></i> View History
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <?php if ($is_active): ?>
                            <script>
                                (function() {
                                    const endTime = new Date("<?php echo $booking['end_time']; ?>").getTime();
                                    
                                    function updateTimer<?php echo $booking['id']; ?>() {
                                        const now = new Date().getTime();
                                        const distance = endTime - now;
                                        
                                        if (distance < 0) {
                                            document.getElementById('timer-<?php echo $booking['id']; ?>').innerHTML = "EXPIRED";
                                            setTimeout(() => location.reload(), 3000);
                                            return;
                                        }
                                        
                                        const hours = Math.floor(distance / (1000 * 60 * 60));
                                        const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                                        const seconds = Math.floor((distance % (1000 * 60)) / 1000);
                                        
                                        document.getElementById('timer-<?php echo $booking['id']; ?>').innerHTML = 
                                            hours.toString().padStart(2, '0') + ":" + 
                                            minutes.toString().padStart(2, '0') + ":" + 
                                            seconds.toString().padStart(2, '0');
                                    }
                                    
                                    updateTimer<?php echo $booking['id']; ?>();
                                    setInterval(updateTimer<?php echo $booking['id']; ?>, 1000);
                                })();
                            </script>
                            <?php endif; ?>
                            
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Nearby Parking Section -->
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-location-dot"></i> Nearby Parking
                        <?php if ($user_city): ?>
                            <span style="font-size: 1rem; color: var(--gray); margin-left: 10px;">
                                in <?php echo htmlspecialchars($user_city); ?>
                            </span>
                        <?php endif; ?>
                    </h2>
                    <a href="find_parking.php" class="view-all">
                        View All <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <?php if (!$user_city): ?>
                    <div class="empty-state">
                        <i class="fas fa-map-marker-alt"></i>
                        <h3>Set Your Location</h3>
                        <p>Please set your location to see available parking nearby.</p>
                        <button class="btn btn-primary" onclick="openLocationModal()">
                            <i class="fas fa-map-marker-alt"></i> Set Location
                        </button>
                    </div>
                <?php elseif (empty($nearby_parking)): ?>
                    <div class="empty-state">
                        <i class="fas fa-parking"></i>
                        <h3>No Available Parking</h3>
                        <p>There are no parking lots with available slots in <?php echo htmlspecialchars($user_city); ?>.</p>
                        <a href="find_parking.php" class="btn btn-primary">
                            <i class="fas fa-search"></i> Search Nearby
                        </a>
                    </div>
                <?php else: ?>
                    <div class="cards-grid">
                        <?php foreach ($nearby_parking as $parking): 
                            $availability_percentage = ($parking['available_slots'] / $parking['total_slots']) * 100;
                            $availability_class = $availability_percentage >= 50 ? 'high' : 
                                                 ($availability_percentage >= 20 ? 'medium' : 'low');
                        ?>
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title"><?php echo htmlspecialchars($parking['name']); ?></h3>
                                    <span class="card-badge badge-active">
                                        <i class="fas fa-car"></i> <?php echo $parking['available_slots']; ?> spots
                                    </span>
                                </div>
                                
                                <div class="parking-price">
                                    UGX<?php echo number_format($parking['price_per_hour'], 0); ?>
                                    <span>/hour</span>
                                </div>
                                
                                <div class="parking-info">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($parking['address']); ?>
                                </div>
                                
                                <div class="parking-stats">
                                    <div class="parking-stat">
                                        <div class="stat-count"><?php echo $parking['available_slots']; ?></div>
                                        <div class="stat-label">Available</div>
                                    </div>
                                    <div class="parking-stat">
                                        <div class="stat-count"><?php echo $parking['total_slots']; ?></div>
                                        <div class="stat-label">Total</div>
                                    </div>
                                    <div class="parking-stat">
                                        <div class="stat-count">24/7</div>
                                        <div class="stat-label">Hours</div>
                                    </div>
                                </div>
                                
                                <div class="card-actions">
                                    <a href="find_parking.php?view=details&location_id=<?php echo $parking['id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-calendar-check"></i> Book Now
                                    </a>
                                    <a href="#" class="btn btn-outline" onclick="toggleFavorite(<?php echo $parking['id']; ?>, this)">
                                        <i class="far fa-star"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Recent History -->
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-history"></i> Recent History
                    </h2>
                    <a href="booking.php" class="view-all">
                        View All <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <?php if (empty($recent_history)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h3>No History Yet</h3>
                        <p>Your parking history will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="history-list">
                        <?php foreach ($recent_history as $history): 
                            $start_time = new DateTime($history['start_time']);
                            $end_time = isset($history['end_time']) ? new DateTime($history['end_time']) : null;
                            $duration = isset($history['duration_minutes']) ? floor($history['duration_minutes'] / 60) . 'h ' . ($history['duration_minutes'] % 60) . 'm' : '--';
                            $is_no_show = ($history['display_status'] == 'no_show_cancelled');
                        ?>
                            <div class="history-item">
                                <div class="history-icon">
                                    <i class="fas fa-parking"></i>
                                </div>
                                <div class="history-details">
                                    <div class="history-location"><?php echo htmlspecialchars($history['location_name']); ?></div>
                                    <div class="history-info">
                                        <?php echo htmlspecialchars($history['address']); ?>
                                    </div>
                                    <div class="history-time">
                                        <?php echo $start_time->format('M d, Y • g:i A'); ?> • <?php echo $duration; ?>
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <div class="history-cost">
                                        <?php if ($history['total_cost'] > 0): ?>
                                            UGX<?php echo number_format($history['total_cost'], 0); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($history['payment_status'] == 'paid'): ?>
                                        <span class="history-status status-paid">Paid</span>
                                    <?php elseif ($is_no_show): ?>
                                        <span class="history-status status-no-show">No-Show</span>
                                    <?php elseif ($history['payment_status'] == 'cancelled'): ?>
                                        <span class="history-status status-cancelled">Cancelled</span>
                                    <?php elseif ($history['end_time'] && strtotime($history['end_time']) < time()): ?>
                                        <span class="history-status status-pending">Payment Due</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
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
                <form id="locationForm" method="GET" action="dashboard.php">
                    <div class="form-group">
                        <label for="cityInput">City</label>
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
            setTimeout(() => document.getElementById('cityInput').focus(), 100);
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
            const btn = event.target.closest('button');
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
                    document.getElementById('cityInput').value = 'Kampala';
                    resetButton(btn, originalHTML);
                    closeLocationModal();
                    document.getElementById('locationForm').submit();
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
        
        // Favorite toggle
        function toggleFavorite(locationId, btn) {
            const icon = btn.querySelector('i');
            
            if (icon.classList.contains('far')) {
                icon.classList.remove('far');
                icon.classList.add('fas');
                btn.style.color = 'var(--warning)';
                showNotification('Location saved to favorites!');
            } else {
                icon.classList.remove('fas');
                icon.classList.add('far');
                btn.style.color = '';
                showNotification('Location removed from favorites');
            }
        }
        
        // Notification function
        function showNotification(message) {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: var(--success);
                color: white;
                padding: 15px 20px;
                border-radius: var(--radius);
                box-shadow: var(--shadow-lg);
                z-index: 1001;
                animation: slideIn 0.3s ease;
                display: flex;
                align-items: center;
                gap: 10px;
            `;
            notification.innerHTML = `<i class="fas fa-check-circle"></i> ${message}`;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }
        
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>