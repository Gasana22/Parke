<?php
// Start session and check login
require_once '../config/database.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Get database connection
$conn = getConnection();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - PARKE</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f5f5f5; 
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 24px; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .logout-btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
        }
        .logout-btn:hover { background: rgba(255,255,255,0.3); }
        
        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            text-align: center;
            transition: transform 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-icon {
            font-size: 40px;
            margin-bottom: 15px;
            color: #667eea;
        }
        .stat-number {
            font-size: 36px;
            font-weight: bold;
            color: #333;
            margin: 10px 0;
        }
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .menu-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            text-decoration: none;
            color: #333;
            transition: all 0.3s;
            border-left: 4px solid #667eea;
        }
        .menu-card:hover {
            background: #667eea;
            color: white;
            transform: translateY(-3px);
        }
        .menu-card:hover .menu-icon {
            color: white;
        }
        .menu-icon {
            font-size: 30px;
            margin-bottom: 15px;
            color: #667eea;
        }
        .menu-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .menu-desc {
            font-size: 14px;
            color: #666;
        }
        .menu-card:hover .menu-desc {
            color: rgba(255,255,255,0.9);
        }
        
        .recent-box {
            background: white;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            padding: 25px;
            margin-bottom: 30px;
        }
        .recent-box h3 {
            margin-bottom: 20px;
            color: #333;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
        }
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .status {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        .status.pending { background: #fff3cd; color: #856404; }
        .status.paid { background: #d4edda; color: #155724; }
        .status.cancelled { background: #f8d7da; color: #721c24; }
        
        @media (max-width: 768px) {
            .container { padding: 0 15px; }
            .header { flex-direction: column; text-align: center; gap: 10px; }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="header">
        <h1><i class="fas fa-tachometer-alt"></i> Admin Dashboard</h1>
        <div class="user-info">
            <span>Welcome, <?php echo $_SESSION['admin_name']; ?></span>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
    
    <div class="container">
        <?php
        // Get statistics
        $stats = [];
        $queries = [
            'users' => "SELECT COUNT(*) as count FROM users WHERE user_type = 'driver'",
            'locations' => "SELECT COUNT(*) as count FROM parking_locations",
            'slots' => "SELECT COUNT(*) as count FROM parking_slots",
            'bookings' => "SELECT COUNT(*) as count FROM bookings WHERE DATE(created_at) = CURDATE()",
            'revenue' => "SELECT COALESCE(SUM(total_cost), 0) as total FROM bookings WHERE payment_status = 'paid' AND DATE(created_at) = CURDATE()"
        ];
        
        foreach ($queries as $key => $query) {
            $result = $conn->query($query);
            $stats[$key] = $result ? $result->fetch_assoc() : ['count' => 0, 'total' => 0];
        }
        ?>
        
        <!-- Statistics Cards -->
       <div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-users"></i></div>
        <div class="stat-number"><?php echo $stats['users']['count']; ?></div>
        <div class="stat-label">Total Users</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-map-marker-alt"></i></div>
        <div class="stat-number"><?php echo $stats['locations']['count']; ?></div>
        <div class="stat-label">Parking Locations</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-parking"></i></div>
        <div class="stat-number"><?php echo $stats['slots']['count']; ?></div>
        <div class="stat-label">Total Slots</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
        <div class="stat-number"><?php echo $stats['bookings']['count']; ?></div>
        <div class="stat-label">Today's Bookings</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div> <!-- Updated -->
        <div class="stat-number">UGX <?php echo number_format($stats['revenue']['total'], 0); ?></div>
        <div class="stat-label">Today's Revenue</div>
    </div>
</div>
        
        <!-- Menu Cards -->
        <div class="menu-grid">
            <a href="locations.php" class="menu-card">
                <div class="menu-icon"><i class="fas fa-map-marker-alt"></i></div>
                <div class="menu-title">Manage Locations</div>
                <div class="menu-desc">Add, edit or delete parking locations</div>
            </a>
            <a href="bookings.php" class="menu-card">
                <div class="menu-icon"><i class="fas fa-receipt"></i></div>
                <div class="menu-title">Manage Bookings</div>
                <div class="menu-desc">View and manage all bookings</div>
            </a>
            <a href="users.php" class="menu-card">
                <div class="menu-icon"><i class="fas fa-users-cog"></i></div>
                <div class="menu-title">Manage Users</div>
                <div class="menu-desc">View and manage user accounts</div>
            </a>
            <a href="slots.php" class="menu-card">
                <div class="menu-icon"><i class="fas fa-parking"></i></div>
                <div class="menu-title">Manage Slots</div>
                <div class="menu-desc">Manage individual parking slots</div>
            </a>
            <a href="reports.php" class="menu-card">
                <div class="menu-icon"><i class="fas fa-chart-bar"></i></div>
                <div class="menu-title">Reports</div>
                <div class="menu-desc">View analytics and reports</div>
            </a>
            <a href="settings.php" class="menu-card">
                <div class="menu-icon"><i class="fas fa-cog"></i></div>
                <div class="menu-title">Settings</div>
                <div class="menu-desc">System settings and configuration</div>
            </a>
        </div>
        
        <!-- Recent Bookings -->
        <div class="recent-box">
            <h3><i class="fas fa-clock"></i> Recent Bookings</h3>
            <?php
            $recent_bookings = $conn->query("
                SELECT b.id, u.username, pl.name as location, b.vehicle_number, 
                       b.total_cost, b.payment_status, b.created_at
                FROM bookings b
                JOIN users u ON b.user_id = u.id
                JOIN parking_locations pl ON b.location_id = pl.id
                ORDER BY b.created_at DESC LIMIT 10
            ");
            
            if ($recent_bookings && $recent_bookings->num_rows > 0):
            ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User</th>
                        <th>Location</th>
                        <th>Vehicle</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                     <?php while($booking = $recent_bookings->fetch_assoc()): ?>
        <tr>
            <td>#<?php echo $booking['id']; ?></td>
            <td><?php echo htmlspecialchars($booking['username']); ?></td>
            <td><?php echo htmlspecialchars($booking['location']); ?></td>
            <td><?php echo htmlspecialchars($booking['vehicle_number']); ?></td>
            <td>UGX <?php echo number_format($booking['total_cost'], 0); ?></td> <!-- Updated -->
            <td><span class="status <?php echo $booking['payment_status']; ?>"><?php echo ucfirst($booking['payment_status']); ?></span></td>
            <td><?php echo date('M d, H:i', strtotime($booking['created_at'])); ?></td>
        </tr>
        <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p style="text-align: center; color: #666; padding: 20px;">No bookings found.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <?php $conn->close(); ?>
</body>
</html>