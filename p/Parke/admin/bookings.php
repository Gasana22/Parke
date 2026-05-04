<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$conn = getConnection();

// Handle status updates
if (isset($_POST['update_status'])) {
    $booking_id = (int)$_POST['booking_id'];
    $status = $conn->real_escape_string($_POST['status']);
    
    $conn->query("UPDATE bookings SET payment_status = '$status' WHERE id = $booking_id");
}

// Handle filters
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_date = isset($_GET['date']) ? $_GET['date'] : '';
$filter_location = isset($_GET['location']) ? (int)$_GET['location'] : '';

$query = "SELECT b.*, u.username, u.email, pl.name as location_name, 
          ps.slot_number, ps.slot_type
          FROM bookings b 
          JOIN users u ON b.user_id = u.id 
          JOIN parking_locations pl ON b.location_id = pl.id
          JOIN parking_slots ps ON b.slot_id = ps.id 
          WHERE 1=1";

if ($filter_status) {
    $query .= " AND b.payment_status = '$filter_status'";
}
if ($filter_date) {
    $query .= " AND DATE(b.created_at) = '$filter_date'";
}
if ($filter_location) {
    $query .= " AND b.location_id = $filter_location";
}

$query .= " ORDER BY b.created_at DESC";
$bookings = $conn->query($query);

// Get locations for filter
$locations = $conn->query("SELECT id, name FROM parking_locations ORDER BY name");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Bookings - PARKE Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Reuse header styles from locations.php */
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header h1 {
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .logout-btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .filters {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .booking-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .booking-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .booking-id {
            font-weight: bold;
            color: #667eea;
            font-size: 18px;
        }
        
        .booking-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .detail-item {
            margin-bottom: 10px;
        }
        
        .detail-label {
            font-weight: 600;
            color: #666;
            font-size: 14px;
            display: block;
            margin-bottom: 3px;
        }
        
        .detail-value {
            color: #333;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-paid {
            background: #d4edda;
            color: #155724;
        }
        
        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }
        
        .action-buttons {
            margin-top: 15px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .status-select {
            padding: 5px 10px;
            border-radius: 5px;
            border: 1px solid #ddd;
            background: white;
        }
        
        .no-bookings {
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 10px;
        }
        
        @media (max-width: 768px) {
            .booking-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filters {
                grid-template-columns: 1fr;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><i class="fas fa-receipt"></i> Manage Bookings</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['admin_name']); ?></span>
            <a href="dashboard.php" class="logout-btn"><i class="fas fa-arrow-left"></i> Dashboard</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
    
    <div class="container">
        <div class="header-actions" style="display: flex; justify-content: space-between; margin-bottom: 20px;">
            <h2>Booking Management</h2>
            <a href="export_bookings.php" class="btn btn-success">
                <i class="fas fa-file-export"></i> Export CSV
            </a>
        </div>
        
        <!-- Filters -->
        <form method="GET" class="filters">
            <div class="filter-group">
                <label>Status</label>
                <select name="status" class="form-control" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="paid" <?php echo $filter_status == 'paid' ? 'selected' : ''; ?>>Paid</option>
                    <option value="cancelled" <?php echo $filter_status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label>Location</label>
                <select name="location" class="form-control" onchange="this.form.submit()">
                    <option value="">All Locations</option>
                    <?php while($loc = $locations->fetch_assoc()): ?>
                        <option value="<?php echo $loc['id']; ?>" 
                            <?php echo $filter_location == $loc['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($loc['name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label>Date</label>
                <input type="date" name="date" class="form-control" value="<?php echo $filter_date; ?>" 
                       onchange="this.form.submit()">
            </div>
            
            <div class="filter-group">
                <label>&nbsp;</label>
                <a href="bookings.php" class="btn" style="background: #6c757d; color: white; width: 100%;">
                    Clear Filters
                </a>
            </div>
        </form>
        
        <!-- Bookings List -->
        <div class="bookings-list">
            <?php if ($bookings && $bookings->num_rows > 0): ?>
                <?php while($booking = $bookings->fetch_assoc()): ?>
                    <div class="booking-card">
                        <div class="booking-header">
                            <div>
                                <span class="booking-id">Booking #<?php echo $booking['id']; ?></span>
                                <span style="margin-left: 20px; color: #666;">
                                    <?php echo date('M d, Y H:i', strtotime($booking['created_at'])); ?>
                                </span>
                            </div>
                            
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                <select name="status" class="status-select" onchange="this.form.submit()">
                                    <option value="pending" <?php echo $booking['payment_status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="paid" <?php echo $booking['payment_status'] == 'paid' ? 'selected' : ''; ?>>Paid</option>
                                    <option value="cancelled" <?php echo $booking['payment_status'] == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                                <input type="hidden" name="update_status" value="1">
                            </form>
                        </div>
                        
                        <div class="booking-details">
                            <div class="detail-item">
                                <span class="detail-label">Customer</span>
                                <div class="detail-value">
                                    <strong><?php echo htmlspecialchars($booking['username']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($booking['email']); ?></small>
                                </div>
                            </div>
                            
                            <div class="detail-item">
                                <span class="detail-label">Location</span>
                                <div class="detail-value"><?php echo htmlspecialchars($booking['location_name']); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <span class="detail-label">Slot Details</span>
                                <div class="detail-value">
                                    Slot #<?php echo htmlspecialchars($booking['slot_number']); ?><br>
                                    <small>Type: <?php echo ucfirst($booking['slot_type']); ?></small>
                                </div>
                            </div>
                            
                            <div class="detail-item">
                                <span class="detail-label">Vehicle</span>
                                <div class="detail-value"><?php echo htmlspecialchars($booking['vehicle_number']); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <span class="detail-label">Duration</span>
                                <div class="detail-value">
                                    <strong>From:</strong> <?php echo date('M d, H:i', strtotime($booking['start_time'])); ?><br>
                                    <?php if ($booking['end_time']): ?>
                                        <strong>To:</strong> <?php echo date('M d, H:i', strtotime($booking['end_time'])); ?>
                                    <?php else: ?>
                                        <em>No end time set</em>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="detail-item">
    <span class="detail-label">Payment</span>
    <div class="detail-value">
    <strong><i class="fas fa-money-bill-wave" style="margin-right: 5px;"></i>
    UGX <?php echo number_format($booking['total_cost'], 0); ?></strong><br>
        <span class="status-badge status-<?php echo $booking['payment_status']; ?>">
            <?php echo ucfirst($booking['payment_status']); ?>
        </span>
    </div>
</div>
                        </div>
                        
                        <div class="action-buttons">
                            <button class="btn btn-primary" onclick="viewBookingDetails(<?php echo $booking['id']; ?>)">
                                <i class="fas fa-eye"></i> View Details
                            </button>
                            <a href="invoice.php?id=<?php echo $booking['id']; ?>" class="btn btn-success" target="_blank">
                                <i class="fas fa-file-invoice"></i> Generate Invoice
                            </a>
                            <?php if ($booking['payment_status'] == 'pending'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                    <input type="hidden" name="status" value="cancelled">
                                    <button type="submit" name="update_status" class="btn" style="background: #dc3545; color: white;">
                                        <i class="fas fa-times"></i> Cancel Booking
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="no-bookings">
                    <i class="fas fa-receipt" style="font-size: 50px; color: #ccc; margin-bottom: 20px;"></i>
                    <h3>No Bookings Found</h3>
                    <p>No bookings match your current filters.</p>
                    <a href="bookings.php" class="btn btn-primary" style="margin-top: 15px;">
                        Clear Filters
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function viewBookingDetails(bookingId) {
            // In a real implementation, you would show a modal with booking details
            // For now, redirect to a booking details page
            window.location.href = `booking_details.php?id=${bookingId}`;
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>