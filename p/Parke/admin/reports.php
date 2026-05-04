<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$conn = getConnection();

// Get report data
// Monthly revenue
$monthly_revenue = $conn->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
           SUM(total_cost) as revenue,
           COUNT(*) as bookings
    FROM bookings 
    WHERE payment_status = 'paid'
    AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month
");

// Top locations
$top_locations = $conn->query("
    SELECT pl.name, COUNT(b.id) as bookings, SUM(b.total_cost) as revenue
    FROM bookings b
    JOIN parking_locations pl ON b.location_id = pl.id
    WHERE b.payment_status = 'paid'
    AND b.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY pl.id
    ORDER BY bookings DESC
    LIMIT 5
");

// User statistics
$user_stats = $conn->query("
    SELECT 
        COUNT(CASE WHEN user_type = 'driver' THEN 1 END) as drivers,
        COUNT(CASE WHEN user_type = 'admin' THEN 1 END) as admins,
        COUNT(*) as total_users
    FROM users
")->fetch_assoc();

// Today's stats
$today_stats = $conn->query("
    SELECT 
        COUNT(*) as bookings,
        SUM(total_cost) as revenue
    FROM bookings 
    WHERE DATE(created_at) = CURDATE()
    AND payment_status = 'paid'
")->fetch_assoc();

// Yesterday's stats
$yesterday_stats = $conn->query("
    SELECT 
        COUNT(*) as bookings,
        SUM(total_cost) as revenue
    FROM bookings 
    WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
    AND payment_status = 'paid'
")->fetch_assoc();

// Calculate changes
$booking_change = 0;
$revenue_change = 0;

if ($yesterday_stats['bookings'] > 0) {
    $booking_change = (($today_stats['bookings'] - $yesterday_stats['bookings']) / $yesterday_stats['bookings'] * 100);
}
if ($yesterday_stats['revenue'] > 0) {
    $revenue_change = (($today_stats['revenue'] - $yesterday_stats['revenue']) / $yesterday_stats['revenue'] * 100);
}

// Get occupancy rate
$total_slots = $conn->query("SELECT SUM(total_slots) as total FROM parking_locations WHERE status = 'active'")->fetch_assoc()['total'] ?? 0;
$available_slots = $conn->query("SELECT SUM(available_slots) as available FROM parking_locations WHERE status = 'active'")->fetch_assoc()['available'] ?? 0;
$occupied_slots = $total_slots - $available_slots;
$occupancy_rate = $total_slots > 0 ? ($occupied_slots / $total_slots * 100) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - PARKE Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Reuse header styles */
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
        
        .report-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .report-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transition: transform 0.3s;
        }
        
        .report-card:hover {
            transform: translateY(-5px);
        }
        
        .report-card h3 {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .report-card .value {
            font-size: 32px;
            font-weight: bold;
            color: #333;
            margin: 10px 0;
        }
        
        .report-card .trend {
            font-size: 14px;
            margin-top: 5px;
        }
        
        .trend.up {
            color: #28a745;
        }
        
        .trend.down {
            color: #dc3545;
        }
        
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .chart-container h3 {
            margin-bottom: 20px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .date-range {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
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
        
        .table-container {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
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
            border-bottom: 2px solid #e9ecef;
        }
        
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        @media (max-width: 768px) {
            .date-range {
                flex-direction: column;
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
        <h1><i class="fas fa-chart-bar"></i> Reports & Analytics</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['admin_name']); ?></span>
            <a href="dashboard.php" class="logout-btn"><i class="fas fa-arrow-left"></i> Dashboard</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
    
    <div class="container">
        <div class="header-actions" style="margin-bottom: 20px;">
            <h2>Analytics Dashboard</h2>
            <div class="date-range">
                <input type="date" class="form-control" id="startDate" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <input type="date" class="form-control" id="endDate" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <button class="btn btn-primary" onclick="updateCharts()">
                    <i class="fas fa-filter"></i> Apply Filter
                </button>
                <button class="btn btn-success" onclick="exportReport()">
                    <i class="fas fa-file-export"></i> Export Report
                </button>
            </div>
        </div>
        
        <!-- Summary Cards -->
        <div class="report-cards">
            <div class="report-card">
                <h3><i class="fas fa-calendar-day"></i> Today's Bookings</h3>
                <div class="value"><?php echo $today_stats['bookings'] ?? 0; ?></div>
                <div class="trend <?php echo $booking_change >= 0 ? 'up' : 'down'; ?>">
                    <i class="fas fa-arrow-<?php echo $booking_change >= 0 ? 'up' : 'down'; ?>"></i>
                    <?php echo number_format(abs($booking_change), 1); ?>% from yesterday
                </div>
            </div>
            
<div class="report-card">
    <h3><i class="fas fa-money-bill-wave"></i> Today's Revenue</h3> <!-- Updated -->
    <div class="value">UGX <?php echo number_format($today_stats['revenue'] ?? 0, 0); ?></div>
    <div class="trend <?php echo $revenue_change >= 0 ? 'up' : 'down'; ?>">
        <i class="fas fa-arrow-<?php echo $revenue_change >= 0 ? 'up' : 'down'; ?>"></i>
        <?php echo number_format(abs($revenue_change), 1); ?>% from yesterday
    </div>
</div>
            
            <div class="report-card">
                <h3><i class="fas fa-users"></i> Total Users</h3>
                <div class="value"><?php echo $user_stats['total_users'] ?? 0; ?></div>
                <div class="trend">
                    <?php echo $user_stats['drivers'] ?? 0; ?> drivers, <?php echo $user_stats['admins'] ?? 0; ?> admins
                </div>
            </div>
            
            <div class="report-card">
                <h3><i class="fas fa-chart-pie"></i> Occupancy Rate</h3>
                <div class="value"><?php echo number_format($occupancy_rate, 1); ?>%</div>
                <div class="trend">
                    <?php echo $occupied_slots; ?> of <?php echo $total_slots; ?> slots occupied
                </div>
            </div>
        </div>
        
        <!-- Revenue Chart -->
        <div class="chart-container">
    <h3><i class="fas fa-chart-line"></i> Monthly Revenue (UGX)</h3>
    <canvas id="revenueChart"></canvas>
</div>
        
        <!-- Top Locations -->
        <div class="table-container">
            <h3><i class="fas fa-map-marker-alt"></i> Top Performing Locations (Last 30 Days)</h3>
            <table>
                <thead>
                    <tr>
                        <th>Location</th>
                        <th>Bookings</th>
                        <th>Revenue</th>
                        <th>Average per Booking</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($top_locations && $top_locations->num_rows > 0): ?>
                        <?php while($location = $top_locations->fetch_assoc()): ?>
                            <tr>
                        <td><strong>UGX <?php echo number_format($location['revenue'], 0); ?></strong></td>
<td>UGX <?php echo $location['bookings'] > 0 ? number_format($location['revenue'] / $location['bookings'], 0) : '0'; ?></td>       
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 20px; color: #666;">
                                <i class="fas fa-info-circle"></i> No data available for the last 30 days
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Recent Activity -->
        <div class="table-container">
            <h3><i class="fas fa-history"></i> Recent Activity</h3>
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Activity</th>
                        <th>Details</th>
                        <th>User</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $recent_activity = $conn->query("
                        (SELECT b.created_at as time, 'Booking' as activity, 
                                CONCAT('Booking #', b.id, ' - $', b.total_cost) as details,
                                u.username as user
                         FROM bookings b
                         JOIN users u ON b.user_id = u.id
                         ORDER BY b.created_at DESC LIMIT 5)
                        UNION
                        (SELECT created_at as time, 'User Registration' as activity,
                                CONCAT('New ', user_type, ' registered') as details,
                                username as user
                         FROM users
                         ORDER BY created_at DESC LIMIT 5)
                        ORDER BY time DESC LIMIT 10
                    ");
                    
                    if ($recent_activity && $recent_activity->num_rows > 0):
                        while($activity = $recent_activity->fetch_assoc()):
                    ?>
                        <tr>
                            <td><?php echo date('M d, H:i', strtotime($activity['time'])); ?></td>
                            <td><span class="status-badge" style="background: #e9ecef; padding: 3px 8px; border-radius: 10px; font-size: 12px;">
                                <?php echo $activity['activity']; ?>
                            </span></td>
                            <td><?php echo htmlspecialchars($activity['details']); ?></td>
                            <td><?php echo htmlspecialchars($activity['user']); ?></td>
                        </tr>
                    <?php 
                        endwhile;
                    else:
                    ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 20px; color: #666;">
                                <i class="fas fa-info-circle"></i> No recent activity
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <script>
        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        
        <?php
        $months = [];
        $revenues = [];
        $bookings = [];
        
        if ($monthly_revenue) {
            while($row = $monthly_revenue->fetch_assoc()) {
                $months[] = date('M Y', strtotime($row['month'] . '-01'));
                $revenues[] = $row['revenue'];
                $bookings[] = $row['bookings'];
            }
        }
        ?>
        
        const revenueChart = new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($months); ?>,
                datasets: [{
                    label: 'Revenue ($)',
                    data: <?php echo json_encode($revenues); ?>,
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
    y: {
        beginAtZero: true,
        ticks: {
            callback: function(value) {
                return 'UGX ' + value.toLocaleString();
            }
        }
    }
}
            }
        });
        
        function updateCharts() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            
            if (startDate && endDate) {
                alert('Date range filtering would be implemented here. Start: ' + startDate + ', End: ' + endDate);
                // In a real implementation, you would:
                // 1. Send AJAX request with dates
                // 2. Update charts with new data
                // 3. Refresh summary cards
            } else {
                alert('Please select both start and end dates.');
            }
        }
        
        function exportReport() {
            // In a real implementation, this would generate and download a report
            window.location.href = 'export_reports.php?type=summary';
        }
        
        // Set default dates (last 30 days)
        window.addEventListener('load', function() {
            const endDate = new Date();
            const startDate = new Date();
            startDate.setDate(startDate.getDate() - 30);
            
            document.getElementById('startDate').valueAsDate = startDate;
            document.getElementById('endDate').valueAsDate = endDate;
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>