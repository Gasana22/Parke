<?php
require_once 'db.php';
require_once 'auth.php';

$location_id = $_SESSION['location_id'];

// Function to safely log activities (if table exists)
function safeLogActivity($conn, $location_id, $slot_id, $action, $details) {
    try {
        // Check if activity_logs table exists
        $check_table = $conn->query("SHOW TABLES LIKE 'activity_logs'");
        if ($check_table->num_rows > 0) {
            $log_stmt = $conn->prepare("
                INSERT INTO activity_logs (location_id, slot_id, action, details, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $log_stmt->bind_param("iiss", $location_id, $slot_id, $action, $details);
            $log_stmt->execute();
        }
    } catch (Exception $e) {
        // Silently fail - logging is not critical
        error_log("Failed to log activity: " . $e->getMessage());
    }
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_slot'])) {
    $slot_id = intval($_POST['slot_id']);
    $new_status = $_POST['new_status'];
    $reason = isset($_POST['reason']) ? mysqli_real_escape_string($conn, $_POST['reason']) : '';
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        if ($new_status == 'available') {
            // Check if there's an active booking for this slot
            $check_booking = $conn->prepare("
                SELECT b.id, b.start_time, b.end_time, b.vehicle_number, u.username 
                FROM bookings b
                JOIN users u ON b.user_id = u.id
                WHERE b.slot_id = ? 
                AND b.location_id = ? 
                AND b.payment_status != 'cancelled'
                AND (b.end_time IS NULL OR b.end_time > NOW())
                ORDER BY b.start_time DESC 
                LIMIT 1
            ");
            $check_booking->bind_param("ii", $slot_id, $location_id);
            $check_booking->execute();
            $booking_result = $check_booking->get_result();
            
            if ($booking_result->num_rows > 0) {
                $booking = $booking_result->fetch_assoc();
                
                // Mark booking as expired/cancelled (no-show)
                $update_booking = $conn->prepare("
                    UPDATE bookings 
                    SET payment_status = 'cancelled',
                        end_time = NOW()
                    WHERE id = ?
                ");
                $update_booking->bind_param("i", $booking['id']);
                $update_booking->execute();
                
                // Log the no-show
                $log_details = "No-show booking for vehicle " . $booking['vehicle_number'] . " - Slot made available";
                safeLogActivity($conn, $location_id, $slot_id, 'no_show', $log_details);
            }
            
            // Update slot status to available
            $update_slot = $conn->prepare("
                UPDATE parking_slots 
                SET status = 'available', 
                    current_vehicle = NULL,
                    reserved_until = NULL,
                    last_updated = NOW()
                WHERE id = ? AND location_id = ?
            ");
            $update_slot->bind_param("ii", $slot_id, $location_id);
            $update_slot->execute();
            
            $_SESSION['message'] = "Slot status updated to Available. Any no-show bookings have been processed.";
            $_SESSION['message_type'] = "success";
            
        } elseif ($new_status == 'maintenance') {
            // First check if slot is occupied
            $check_slot = $conn->prepare("SELECT status FROM parking_slots WHERE id = ? AND location_id = ?");
            $check_slot->bind_param("ii", $slot_id, $location_id);
            $check_slot->execute();
            $slot_result = $check_slot->get_result();
            $slot_data = $slot_result->fetch_assoc();
            
            if ($slot_data['status'] == 'occupied') {
                throw new Exception("Cannot set to maintenance while slot is occupied. Please ensure the slot is empty first.");
            }
            
            // Update slot to maintenance
            $update_slot = $conn->prepare("
                UPDATE parking_slots 
                SET status = 'maintenance', 
                    maintenance_reason = ?,
                    current_vehicle = NULL,
                    reserved_until = NULL,
                    last_updated = NOW()
                WHERE id = ? AND location_id = ?
            ");
            $update_slot->bind_param("sii", $reason, $slot_id, $location_id);
            $update_slot->execute();
            
            // Log the maintenance
            $log_details = "Slot set to maintenance. Reason: " . $reason;
            safeLogActivity($conn, $location_id, $slot_id, 'maintenance', $log_details);
            
            $_SESSION['message'] = "Slot status updated to Maintenance.";
            $_SESSION['message_type'] = "success";
            
        } elseif ($new_status == 'occupied') {
            // Update slot to occupied (for manual check-ins)
            $vehicle_number = isset($_POST['vehicle_number']) ? mysqli_real_escape_string($conn, $_POST['vehicle_number']) : '';
            
            if (empty($vehicle_number)) {
                throw new Exception("Vehicle number is required when marking a slot as occupied.");
            }
            
            // Check if there's a booking for this vehicle/slot
            $check_booking = $conn->prepare("
                SELECT id FROM bookings 
                WHERE slot_id = ? 
                AND vehicle_number = ? 
                AND payment_status = 'pending'
                AND (end_time IS NULL OR end_time > NOW())
                LIMIT 1
            ");
            $check_booking->bind_param("is", $slot_id, $vehicle_number);
            $check_booking->execute();
            $booking_result = $check_booking->get_result();
            
            if ($booking_result->num_rows > 0) {
                $booking = $booking_result->fetch_assoc();
                // Update booking to paid/active
                $update_booking = $conn->prepare("
                    UPDATE bookings 
                    SET payment_status = 'paid',
                        start_time = NOW()
                    WHERE id = ?
                ");
                $update_booking->bind_param("i", $booking['id']);
                $update_booking->execute();
            }
            
            $update_slot = $conn->prepare("
                UPDATE parking_slots 
                SET status = 'occupied', 
                    current_vehicle = ?,
                    reserved_until = NULL,
                    last_updated = NOW()
                WHERE id = ? AND location_id = ?
            ");
            $update_slot->bind_param("sii", $vehicle_number, $slot_id, $location_id);
            $update_slot->execute();
            
            // Log the manual check-in
            $log_details = "Manual check-in for vehicle: " . $vehicle_number;
            safeLogActivity($conn, $location_id, $slot_id, 'manual_checkin', $log_details);
            
            $_SESSION['message'] = "Slot marked as Occupied with vehicle: " . $vehicle_number;
            $_SESSION['message_type'] = "success";
        }
        
        // Update available slots count in parking_locations
        $update_available = $conn->prepare("
            UPDATE parking_locations pl
            SET available_slots = (
                SELECT COUNT(*) 
                FROM parking_slots ps 
                WHERE ps.location_id = pl.id 
                AND ps.status = 'available'
            )
            WHERE pl.id = ?
        ");
        $update_available->bind_param("i", $location_id);
        $update_available->execute();
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['message'] = "Error: " . $e->getMessage();
        $_SESSION['message_type'] = "error";
    }
    
    // Redirect to refresh the page
    header("Location: slot_grid.php");
    exit();
}

// Handle no-show cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_no_show'])) {
    $booking_id = intval($_POST['booking_id']);
    $slot_id = intval($_POST['slot_id']);
    
    $conn->begin_transaction();
    
    try {
        // First, get booking details for logging
        $get_booking = $conn->prepare("
            SELECT vehicle_number, start_time 
            FROM bookings 
            WHERE id = ? AND payment_status = 'pending'
        ");
        $get_booking->bind_param("i", $booking_id);
        $get_booking->execute();
        $booking_info = $get_booking->get_result()->fetch_assoc();
        
        // Cancel the booking
        $update_booking = $conn->prepare("
            UPDATE bookings 
            SET payment_status = 'cancelled',
                end_time = NOW()
            WHERE id = ? AND payment_status = 'pending'
        ");
        $update_booking->bind_param("i", $booking_id);
        $update_booking->execute();
        
        // Update slot status to available
        $update_slot = $conn->prepare("
            UPDATE parking_slots 
            SET status = 'available', 
                current_vehicle = NULL,
                reserved_until = NULL,
                last_updated = NOW()
            WHERE id = ? AND location_id = ?
        ");
        $update_slot->bind_param("ii", $slot_id, $location_id);
        $update_slot->execute();
        
        // Log the no-show cancellation
        if ($booking_info) {
            $log_details = "No-show booking cancelled for vehicle " . $booking_info['vehicle_number'] . 
                          " (booked for " . date('h:i A', strtotime($booking_info['start_time'])) . ")";
            safeLogActivity($conn, $location_id, $slot_id, 'no_show_cancelled', $log_details);
        }
        
        // Update available slots count
        $update_available = $conn->prepare("
            UPDATE parking_locations pl
            SET available_slots = (
                SELECT COUNT(*) 
                FROM parking_slots ps 
                WHERE ps.location_id = pl.id 
                AND ps.status = 'available'
            )
            WHERE pl.id = ?
        ");
        $update_available->bind_param("i", $location_id);
        $update_available->execute();
        
        $conn->commit();
        
        $_SESSION['message'] = "No-show booking has been cancelled. Slot is now available.";
        $_SESSION['message_type'] = "success";
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['message'] = "Error cancelling booking: " . $e->getMessage();
        $_SESSION['message_type'] = "error";
    }
    
    header("Location: slot_grid.php");
    exit();
}

// Fetch slots with additional booking info
$stmt = $conn->prepare("
    SELECT ps.*, 
           b.id as booking_id,
           b.start_time as booking_start_time, 
           b.end_time as booking_end_time,
           b.vehicle_number as booked_vehicle,
           b.payment_status,
           b.user_id as booking_user_id,
           u.username as booked_by,
           TIMESTAMPDIFF(MINUTE, b.start_time, NOW()) as minutes_since_start
    FROM parking_slots ps
    LEFT JOIN bookings b ON ps.id = b.slot_id 
        AND b.location_id = ps.location_id 
        AND b.payment_status = 'pending'
        AND (b.end_time IS NULL OR b.end_time > NOW())
    LEFT JOIN users u ON b.user_id = u.id
    WHERE ps.location_id = ?
    ORDER BY ps.slot_number ASC
");
$stmt->bind_param("i", $location_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Slot Grid View - Parking Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* All your existing CSS styles remain the same */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f8f9fc;
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 280px;
            background: #0f0f1a;
            color: white;
            height: 100vh;
            overflow-y: auto;
            padding: 24px 0;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 0 24px;
            margin-bottom: 32px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-header .avatar {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 600;
            color: white;
        }

        .sidebar-header .user-info h3 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .sidebar-header .user-info p {
            font-size: 13px;
            color: #8b8b9e;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .nav-section {
            margin-bottom: 24px;
        }

        .nav-section-title {
            padding: 0 24px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #5e5e7a;
            margin-bottom: 12px;
        }

        .nav-item {
            padding: 10px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #a0a0b8;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
        }

        .nav-item.active {
            background: rgba(102, 126, 234, 0.15);
            color: white;
            border-left: 3px solid #667eea;
        }

        .nav-item i {
            width: 20px;
            font-size: 16px;
        }

        .create-btn {
            margin: 16px 24px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
            color: white;
            padding: 12px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .create-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            overflow-y: auto;
            padding: 24px 32px;
        }

        .welcome-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }

        .welcome-text h1 {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a2c;
            margin-bottom: 8px;
        }

        .welcome-text p {
            color: #6b7280;
            font-size: 14px;
        }

        .avatar-large {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.2);
        }

        /* Message Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease;
        }

        .alert.success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            color: #065f46;
        }

        .alert.error {
            background: #fee2e2;
            border-left: 4px solid #ff6b6b;
            color: #991b1b;
        }

        .alert.warning {
            background: #fef3c7;
            border-left: 4px solid #fbbf24;
            color: #92400e;
        }

        .alert i {
            font-size: 20px;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .legend {
            background: white;
            border-radius: 16px;
            padding: 16px 24px;
            margin-bottom: 24px;
            display: flex;
            gap: 32px;
            border: 1px solid #f0f0f5;
            flex-wrap: wrap;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #1a1a2c;
        }

        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 4px;
        }

        .legend-color.available { background: #10b981; }
        .legend-color.occupied { background: #ff6b6b; }
        .legend-color.maintenance { background: #fbbf24; }
        .legend-color.reserved { background: #3b82f6; }
        .legend-color.no-show { background: #ff9800; }

        .slot-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }

        .slot-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #f0f0f5;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .slot-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.05);
        }

        .slot-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }

        .slot-card.available::before { background: #10b981; }
        .slot-card.occupied::before { background: #ff6b6b; }
        .slot-card.maintenance::before { background: #fbbf24; }
        .slot-card.reserved::before { background: #3b82f6; }
        .slot-card.no-show::before { background: #ff9800; }

        .slot-number {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .slot-number span {
            font-size: 11px;
            padding: 4px 8px;
            border-radius: 20px;
            font-weight: 500;
        }

        .slot-card.available .slot-number span {
            background: #d1fae5;
            color: #065f46;
        }

        .slot-card.occupied .slot-number span {
            background: #fee2e2;
            color: #991b1b;
        }

        .slot-card.maintenance .slot-number span {
            background: #fef3c7;
            color: #92400e;
        }

        .slot-card.reserved .slot-number span {
            background: #dbeafe;
            color: #1e40af;
        }

        .slot-card.no-show .slot-number span {
            background: #ffe0b2;
            color: #e65100;
        }

        .slot-status {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .status-icon {
            font-size: 32px;
            text-align: center;
        }

        .vehicle-info {
            background: #f8f9fc;
            padding: 10px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 500;
        }

        .vehicle-info i {
            color: #667eea;
        }

        .maintenance-info {
            background: #fef3c7;
            color: #92400e;
            padding: 10px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 500;
        }

        .booking-info {
            background: #dbeafe;
            color: #1e40af;
            padding: 10px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 500;
            margin-top: 8px;
        }

        .no-show-badge {
            background: #ff9800;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            margin-left: 8px;
        }

        .slot-type-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 12px;
            background: rgba(0,0,0,0.05);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #f0f0f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            font-size: 20px;
            font-weight: 600;
            color: #1a1a2c;
        }

        .close {
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            color: #aaa;
            transition: color 0.3s;
        }

        .close:hover {
            color: #333;
        }

        .modal-body {
            padding: 24px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #1a1a2c;
            font-size: 14px;
        }

        .form-group select,
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e0e0e5;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            transition: border-color 0.3s;
        }

        .form-group select:focus,
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #f0f0f5;
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #f0f0f5;
            color: #666;
        }

        .btn-secondary:hover {
            background: #e0e0e5;
        }

        .btn-danger {
            background: #ff6b6b;
            color: white;
        }

        .btn-danger:hover {
            background: #ff5252;
        }

        .slot-info {
            background: #f8f9fc;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .slot-info p {
            margin: 4px 0;
            font-size: 13px;
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 80px;
            }
            .sidebar-header .user-info,
            .nav-section-title,
            .nav-item span:not(.nav-item i) {
                display: none;
            }
            .create-btn span {
                display: none;
            }
            .main-content {
                padding: 16px;
            }
            .slot-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="avatar">
                <?php echo substr($_SESSION['location_name'], 0, 2); ?>
            </div>
            <div class="user-info">
                <h3><?php echo htmlspecialchars($_SESSION['location_name']); ?></h3>
                <p><i class="fas fa-circle" style="color: #10b981; font-size: 8px;"></i> Online</p>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Navigation</div>
            <a href="location_dashboard.php" class="nav-item">
                <i class="fas fa-home"></i>
                <span>Home</span>
            </a>
            <a href="slot_grid.php" class="nav-item active">
                <i class="fas fa-th"></i>
                <span>Slot Grid</span>
            </a>
            <a href="recent_activity.php" class="nav-item">
                <i class="fas fa-history"></i>
                <span>Activity</span>
            </a>
            <a href="revenue_reports.php" class="nav-item">
                <i class="fas fa-chart-bar"></i>
                <span>Revenue</span>
            </a>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Quick Access</div>
            <a href="profile.php" class="nav-item">
                <i class="fas fa-user"></i>
                <span>Profile</span>
            </a>
            <a href="logout.php" class="nav-item">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>

        <button class="create-btn" onclick="window.location.href='location_dashboard.php'">
            <i class="fas fa-arrow-left"></i>
            <span>Back to Dashboard</span>
        </button>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="welcome-section">
            <div class="welcome-text">
                <h1>Slot Grid View</h1>
                <p>Click on any slot to update its status or manage bookings</p>
            </div>
            <div class="avatar-large">
                <i class="fas fa-th"></i>
            </div>
        </div>

        <!-- Display Messages -->
        <?php if(isset($_SESSION['message'])): ?>
            <div class="alert <?php echo $_SESSION['message_type']; ?>">
                <i class="fas <?php echo $_SESSION['message_type'] == 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                <span><?php echo htmlspecialchars($_SESSION['message']); ?></span>
            </div>
            <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
        <?php endif; ?>

        <!-- Legend -->
        <div class="legend">
            <div class="legend-item">
                <div class="legend-color available"></div>
                <span>Available</span>
            </div>
            <div class="legend-item">
                <div class="legend-color occupied"></div>
                <span>Occupied</span>
            </div>
            <div class="legend-item">
                <div class="legend-color maintenance"></div>
                <span>Under Maintenance</span>
            </div>
            <div class="legend-item">
                <div class="legend-color reserved"></div>
                <span>Has Active Booking</span>
            </div>
            <div class="legend-item">
                <div class="legend-color no-show"></div>
                <span>No-Show (Grace Period Expired)</span>
            </div>
        </div>

        <!-- Slot Grid -->
        <div class="slot-grid">
            <?php while($slot = $result->fetch_assoc()): 
                $has_booking = $slot['booking_id'] !== null;
                $is_no_show = false;
                $minutes_past = 0;
                
                if ($has_booking && $slot['status'] == 'available' && $slot['booking_start_time']) {
                    $booking_time = strtotime($slot['booking_start_time']);
                    $current_time = time();
                    $minutes_past = floor(($current_time - $booking_time) / 60);
                    
                    // Check if grace period (10 minutes) has passed
                    if ($minutes_past >= 10) {
                        $is_no_show = true;
                    }
                }
                
                $slot_class = $slot['status'];
                if ($is_no_show) {
                    $slot_class = 'no-show';
                } elseif ($has_booking && $slot['status'] == 'available') {
                    $slot_class = 'reserved';
                }
            ?>
                <div class="slot-card <?php echo $slot_class; ?>" 
                     onclick='openModal(<?php echo json_encode($slot); ?>, <?php echo $is_no_show ? 'true' : 'false'; ?>, <?php echo $minutes_past; ?>)'>
                    <div class="slot-number">
                        Slot <?php echo htmlspecialchars($slot['slot_number']); ?>
                        <span><?php 
                            if ($is_no_show) {
                                echo 'No-Show';
                            } else {
                                echo ucfirst($slot['status']);
                            }
                        ?></span>
                    </div>
                    
                    <?php if($slot['slot_type'] != 'standard'): ?>
                        <div class="slot-type-badge">
                            <?php if($slot['slot_type'] == 'disabled'): ?>
                                <i class="fas fa-wheelchair"></i> Disabled
                            <?php elseif($slot['slot_type'] == 'electric'): ?>
                                <i class="fas fa-charging-station"></i> EV
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="slot-status">
                        <?php if($slot['status'] == 'occupied'): ?>
                            <div class="status-icon">🚗</div>
                            <div class="vehicle-info">
                                <i class="fas fa-car"></i>
                                <?php echo htmlspecialchars($slot['current_vehicle']); ?>
                            </div>
                            <?php if($has_booking): ?>
                                <div class="booking-info">
                                    <i class="fas fa-clock"></i>
                                    Booked until: <?php echo $slot['booking_end_time'] ? date('h:i A', strtotime($slot['booking_end_time'])) : 'Ongoing'; ?>
                                </div>
                            <?php endif; ?>
                        <?php elseif($slot['status'] == 'maintenance'): ?>
                            <div class="status-icon">⚠️</div>
                            <div class="maintenance-info">
                                <i class="fas fa-tools"></i>
                                <?php echo htmlspecialchars($slot['maintenance_reason']) ?: 'Under Maintenance'; ?>
                            </div>
                        <?php elseif($is_no_show): ?>
                            <div class="status-icon">⏰</div>
                            <div class="booking-info" style="background: #ffe0b2; color: #e65100;">
                                <i class="fas fa-clock"></i>
                                <strong>NO-SHOW DETECTED</strong><br>
                                Vehicle: <?php echo htmlspecialchars($slot['booked_vehicle']); ?><br>
                                Booked for: <?php echo date('h:i A', strtotime($slot['booking_start_time'])); ?><br>
                                <span style="color: #ff6b6b;">Grace period expired (<?php echo $minutes_past; ?> mins late)</span>
                            </div>
                        <?php elseif($has_booking && $slot['status'] == 'available'): ?>
                            <div class="status-icon">📅</div>
                            <div class="booking-info">
                                <i class="fas fa-calendar-alt"></i>
                                <strong>Upcoming Booking</strong><br>
                                Vehicle: <?php echo htmlspecialchars($slot['booked_vehicle']); ?><br>
                                <?php if($slot['booking_start_time']): ?>
                                    From: <?php echo date('h:i A', strtotime($slot['booking_start_time'])); ?>
                                <?php endif; ?>
                                <?php if($slot['booking_end_time']): ?>
                                    Until: <?php echo date('h:i A', strtotime($slot['booking_end_time'])); ?>
                                <?php endif; ?>
                                <?php if($minutes_past > 0 && $minutes_past < 10): ?>
                                    <br><span class="no-show-badge"><?php echo (10 - $minutes_past); ?> mins remaining in grace period</span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="status-icon">✅</div>
                            <div class="vehicle-info" style="justify-content: center; background: #f0f0ff; color: #667eea;">
                                <i class="fas fa-check-circle"></i>
                                Available Now
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>

    <!-- Modal for Status Update -->
    <div id="updateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Manage Slot</h2>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="slot_id" id="slot_id">
                    <input type="hidden" name="update_slot" value="1">
                    
                    <div class="slot-info" id="slotInfo">
                        <p><strong>Slot Number:</strong> <span id="slot_number_display"></span></p>
                        <p><strong>Slot Type:</strong> <span id="slot_type_display"></span></p>
                        <p><strong>Current Status:</strong> <span id="current_status_display"></span></p>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_status">Update Status To</label>
                        <select name="new_status" id="new_status" required onchange="toggleFields()">
                            <option value="">Select status...</option>
                            <option value="available">Available (Clear Slot)</option>
                            <option value="occupied">Occupied (Manual Check-in)</option>
                            <option value="maintenance">Under Maintenance</option>
                        </select>
                    </div>
                    
                    <div id="vehicle_field" style="display: none;">
                        <div class="form-group">
                            <label for="vehicle_number">Vehicle Number *</label>
                            <input type="text" name="vehicle_number" id="vehicle_number" placeholder="e.g., ABC-1234">
                            <small style="color: #666;">Required when marking as occupied</small>
                        </div>
                    </div>
                    
                    <div id="maintenance_field" style="display: none;">
                        <div class="form-group">
                            <label for="reason">Maintenance Reason (Optional)</label>
                            <textarea name="reason" id="reason" rows="3" placeholder="Enter reason for maintenance..."></textarea>
                        </div>
                    </div>
                    
                    <div id="warning_message" class="alert error" style="display: none; margin-top: 16px;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span id="warning_text"></span>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal for No-Show Cancellation -->
    <div id="noShowModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>No-Show Detected</h2>
                <span class="close" onclick="closeNoShowModal()">&times;</span>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="booking_id" id="no_show_booking_id">
                    <input type="hidden" name="slot_id" id="no_show_slot_id">
                    <input type="hidden" name="cancel_no_show" value="1">
                    
                    <div class="alert warning" style="margin-bottom: 20px;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>This booking has exceeded the 10-minute grace period.</span>
                    </div>
                    
                    <div class="slot-info" id="noShowSlotInfo">
                        <p><strong>Slot Number:</strong> <span id="no_show_slot_number"></span></p>
                        <p><strong>Booked Vehicle:</strong> <span id="no_show_vehicle"></span></p>
                        <p><strong>Booking Time:</strong> <span id="no_show_booking_time"></span></p>
                        <p><strong>Minutes Late:</strong> <span id="no_show_minutes_late"></span> minutes</p>
                    </div>
                    
                    <p style="margin-top: 16px; color: #666;">Cancelling this booking will make the slot available for other customers.</p>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeNoShowModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Cancel Booking & Free Slot</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentSlot = null;
        let currentIsNoShow = false;
        
        function openModal(slot, isNoShow, minutesPast) {
            currentSlot = slot;
            currentIsNoShow = isNoShow;
            
            if (isNoShow) {
                // Open the no-show modal instead
                document.getElementById('no_show_booking_id').value = slot.booking_id;
                document.getElementById('no_show_slot_id').value = slot.id;
                document.getElementById('no_show_slot_number').innerHTML = 'Slot ' + slot.slot_number;
                document.getElementById('no_show_vehicle').innerHTML = slot.booked_vehicle;
                document.getElementById('no_show_booking_time').innerHTML = slot.booking_start_time ? new Date(slot.booking_start_time).toLocaleTimeString() : 'N/A';
                document.getElementById('no_show_minutes_late').innerHTML = minutesPast;
                document.getElementById('noShowModal').style.display = 'block';
                return;
            }
            
            // Regular modal for non-no-show slots
            document.getElementById('slot_id').value = slot.id;
            document.getElementById('slot_number_display').innerHTML = 'Slot ' + slot.slot_number;
            
            // Display slot type
            let slotTypeText = '';
            if(slot.slot_type === 'standard') slotTypeText = 'Standard';
            else if(slot.slot_type === 'disabled') slotTypeText = 'Disabled (Handicap)';
            else if(slot.slot_type === 'electric') slotTypeText = 'Electric Vehicle (EV)';
            document.getElementById('slot_type_display').innerHTML = slotTypeText;
            
            document.getElementById('current_status_display').innerHTML = slot.status.charAt(0).toUpperCase() + slot.status.slice(1);
            document.getElementById('new_status').value = '';
            document.getElementById('vehicle_field').style.display = 'none';
            document.getElementById('maintenance_field').style.display = 'none';
            document.getElementById('warning_message').style.display = 'none';
            document.getElementById('updateModal').style.display = 'block';
            
            // Clear input fields
            document.getElementById('vehicle_number').value = '';
            document.getElementById('reason').value = '';
        }
        
        function closeModal() {
            document.getElementById('updateModal').style.display = 'none';
            currentSlot = null;
            currentIsNoShow = false;
        }
        
        function closeNoShowModal() {
            document.getElementById('noShowModal').style.display = 'none';
        }
        
        function toggleFields() {
            const status = document.getElementById('new_status').value;
            const vehicleField = document.getElementById('vehicle_field');
            const maintenanceField = document.getElementById('maintenance_field');
            const warningMessage = document.getElementById('warning_message');
            const warningText = document.getElementById('warning_text');
            
            vehicleField.style.display = 'none';
            maintenanceField.style.display = 'none';
            warningMessage.style.display = 'none';
            
            if (status === 'occupied') {
                vehicleField.style.display = 'block';
                document.getElementById('vehicle_number').required = true;
                if (currentSlot && currentSlot.status === 'available' && currentSlot.booking_id) {
                    warningText.innerHTML = '⚠️ This slot has an active booking. Marking as occupied will confirm the check-in.';
                    warningMessage.style.display = 'block';
                } else if (currentSlot && currentSlot.status === 'available') {
                    warningText.innerHTML = 'ℹ️ Marking this slot as occupied for manual check-in.';
                    warningMessage.style.display = 'block';
                    warningMessage.className = 'alert success';
                }
            } else if (status === 'maintenance') {
                maintenanceField.style.display = 'block';
                if (currentSlot && currentSlot.status === 'occupied') {
                    warningText.innerHTML = '⚠️ This slot is currently occupied. Please ensure the vehicle has left before setting to maintenance.';
                    warningMessage.style.display = 'block';
                    warningMessage.className = 'alert error';
                }
            } else if (status === 'available') {
                if (currentSlot && currentSlot.booking_id && currentSlot.status === 'available') {
                    warningText.innerHTML = '⚠️ This slot has an active booking. Making it available will mark the booking as no-show/cancelled.';
                    warningMessage.style.display = 'block';
                    warningMessage.className = 'alert error';
                } else if (currentSlot && currentSlot.status === 'occupied') {
                    warningText.innerHTML = '⚠️ This will mark the slot as available and free up the space. Any associated bookings will be marked as completed.';
                    warningMessage.style.display = 'block';
                    warningMessage.className = 'alert error';
                }
            }
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const updateModal = document.getElementById('updateModal');
            const noShowModal = document.getElementById('noShowModal');
            if (event.target == updateModal) {
                closeModal();
            }
            if (event.target == noShowModal) {
                closeNoShowModal();
            }
        }
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert:not(#warning_message)');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => {
                    if(alert.parentNode) alert.remove();
                }, 300);
            });
        }, 5000);
    </script>
</body>
</html>