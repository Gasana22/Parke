<?php
require_once 'db.php';
require_once 'auth.php';

$location_id = $_SESSION['location_id'];

// Today
$stmt = $conn->prepare("
    SELECT SUM(total_cost) as total 
    FROM bookings 
    WHERE location_id = ? 
    AND DATE(start_time) = CURDATE()
    AND payment_status = 'paid'
");
$stmt->bind_param("i", $location_id);
$stmt->execute();
$today = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

// This Week
$stmt = $conn->prepare("
    SELECT SUM(total_cost) as total 
    FROM bookings 
    WHERE location_id = ? 
    AND YEARWEEK(start_time, 1) = YEARWEEK(CURDATE(), 1)
    AND payment_status = 'paid'
");
$stmt->bind_param("i", $location_id);
$stmt->execute();
$week = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

// This Month
$stmt = $conn->prepare("
    SELECT SUM(total_cost) as total 
    FROM bookings 
    WHERE location_id = ? 
    AND MONTH(start_time) = MONTH(CURDATE())
    AND YEAR(start_time) = YEAR(CURDATE())
    AND payment_status = 'paid'
");
$stmt->bind_param("i", $location_id);
$stmt->execute();
$month = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

// Total Revenue
$stmt = $conn->prepare("
    SELECT SUM(total_cost) as total 
    FROM bookings 
    WHERE location_id = ?
    AND payment_status = 'paid'
");
$stmt->bind_param("i", $location_id);
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

// Get daily revenue for chart (last 7 days)
$daily_stmt = $conn->prepare("
    SELECT DATE(start_time) as date, SUM(total_cost) as revenue
    FROM bookings
    WHERE location_id = ?
    AND start_time >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    AND payment_status = 'paid'
    GROUP BY DATE(start_time)
    ORDER BY date ASC
");
$daily_stmt->bind_param("i", $location_id);
$daily_stmt->execute();
$daily_result = $daily_stmt->get_result();

$dates = [];
$revenues = [];
while($row = $daily_result->fetch_assoc()) {
    $dates[] = date('M d', strtotime($row['date']));
    $revenues[] = $row['revenue'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue Reports</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
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

        /* Date Range */
        .date-range {
            background: white;
            border-radius: 16px;
            padding: 16px 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid #f0f0f5;
        }

        .range-selector {
            display: flex;
            gap: 8px;
        }

        .range-btn {
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #6b7280;
            background: transparent;
            border: none;
        }

        .range-btn:hover {
            background: #f0f0ff;
            color: #667eea;
        }

        .range-btn.active {
            background: #667eea;
            color: white;
        }

        .date-picker {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f8f9fc;
            padding: 8px 16px;
            border-radius: 30px;
            border: 1px solid #f0f0f5;
        }

        .date-picker i {
            color: #6b7280;
        }

        .date-picker span {
            font-size: 13px;
            color: #1a1a2c;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #f0f0f5;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.05);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .stat-header h3 {
            font-size: 14px;
            font-weight: 500;
            color: #6b7280;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            background: #f0f0ff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #667eea;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #1a1a2c;
            margin-bottom: 8px;
        }

        .stat-change {
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .stat-change.positive {
            color: #10b981;
        }

        .stat-change.negative {
            color: #ff6b6b;
        }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        .chart-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            border: 1px solid #f0f0f5;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: #1a1a2c;
        }

        .chart-header select {
            padding: 6px 12px;
            border-radius: 8px;
            border: 1px solid #f0f0f5;
            font-size: 13px;
            color: #1a1a2c;
            outline: none;
        }

        canvas {
            width: 100%;
            height: 250px;
        }

        /* Revenue Table */
        .revenue-table {
            background: white;
            border-radius: 20px;
            padding: 24px;
            border: 1px solid #f0f0f5;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .table-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: #1a1a2c;
        }

        .view-all {
            color: #667eea;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 12px;
            font-size: 13px;
            font-weight: 600;
            color: #6b7280;
            border-bottom: 1px solid #f0f0f5;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #f0f0f5;
            font-size: 14px;
        }

        .revenue-amount {
            font-weight: 600;
            color: #10b981;
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
                <h3><?php echo $_SESSION['location_name']; ?></h3>
                <p><i class="fas fa-circle" style="color: #10b981; font-size: 8px;"></i> Online</p>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Navigation</div>
            <a href="location_dashboard.php" class="nav-item">
                <i class="fas fa-home"></i>
                Home
            </a>
            <a href="slot_grid.php" class="nav-item">
                <i class="fas fa-th"></i>
                Slot Grid
            </a>
            <a href="recent_activity.php" class="nav-item">
                <i class="fas fa-history"></i>
                Activity
            </a>
            <a href="revenue_reports.php" class="nav-item active">
                <i class="fas fa-chart-bar"></i>
                Revenue
            </a>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Quick Access</div>
            <a href="profile.php" class="nav-item">
                <i class="fas fa-user"></i>
                Profile
            </a>
            <a href="logout.php" class="nav-item">
                <i class="fas fa-sign-out-alt"></i>
                Logout
            </a>
        </div>

        <button class="create-btn" onclick="window.location.href='slot_grid.php'">
            <i class="fas fa-plus-circle"></i>
            View Slot Grid
        </button>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="welcome-section">
            <div class="welcome-text">
                <h1>Revenue Reports</h1>
                <p>Track your earnings and financial performance</p>
            </div>
            <div class="avatar-large">
                <i class="fas fa-chart-bar"></i>
            </div>
        </div>

        <!-- Date Range -->
        <div class="date-range">
            <div class="range-selector">
                <button class="range-btn active">Today</button>
                <button class="range-btn">This Week</button>
                <button class="range-btn">This Month</button>
                <button class="range-btn">This Year</button>
                <button class="range-btn">Custom</button>
            </div>
            <div class="date-picker">
                <i class="fas fa-calendar"></i>
                <span>Mar 1 - Mar 31, 2024</span>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <h3>Today's Revenue</h3>
                    <div class="stat-icon">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                </div>
                <div class="stat-value">UGX<?php echo number_format($today,2); ?></div>
                <div class="stat-change positive">
                    <i class="fas fa-arrow-up"></i> +12.5%
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <h3>This Week</h3>
                    <div class="stat-icon">
                        <i class="fas fa-calendar-week"></i>
                    </div>
                </div>
                <div class="stat-value">UGX<?php echo number_format($week,2); ?></div>
                <div class="stat-change positive">
                    <i class="fas fa-arrow-up"></i> +8.3%
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <h3>This Month</h3>
                    <div class="stat-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                </div>
                <div class="stat-value">UGX<?php echo number_format($month,); ?></div>
                <div class="stat-change positive">
                    <i class="fas fa-arrow-up"></i> +15.2%
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <h3>Total Revenue</h3>
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
                <div class="stat-value">UGX<?php echo number_format($total,); ?></div>
                <div class="stat-change positive">
                    <i class="fas fa-arrow-up"></i> +24.7%
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="charts-grid">
            <div class="chart-card">
                <div class="chart-header">
                    <h3>Revenue Overview</h3>
                    <select>
                        <option>Last 7 days</option>
                        <option>Last 30 days</option>
                        <option>Last 90 days</option>
                    </select>
                </div>
                <canvas id="revenueChart"></canvas>
            </div>

            <div class="chart-card">
                <div class="chart-header">
                    <h3>Revenue Distribution</h3>
                </div>
                <canvas id="distributionChart"></canvas>
            </div>
        </div>

        <!-- Revenue Table -->
        <div class="revenue-table">
            <div class="table-header">
                <h3>Daily Revenue Breakdown</h3>
                <a href="#" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Transactions</th>
                        <th>Hours Parked</th>
                        <th>Revenue</th>
                        <th>Growth</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $daily_breakdown = $conn->prepare("
                        SELECT 
                            DATE(start_time) as date,
                            COUNT(*) as transactions,
                            SUM(TIMESTAMPDIFF(HOUR, start_time, end_time)) as hours,
                            SUM(total_cost) as revenue
                        FROM bookings
                        WHERE location_id = ?
                        AND start_time >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                        AND payment_status = 'paid'
                        GROUP BY DATE(start_time)
                        ORDER BY date DESC
                        LIMIT 7
                    ");
                    $daily_breakdown->bind_param("i", $location_id);
                    $daily_breakdown->execute();
                    $breakdown_result = $daily_breakdown->get_result();
                    
                    while($row = $breakdown_result->fetch_assoc()):
                    ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($row['date'])); ?></td>
                        <td><?php echo $row['transactions']; ?></td>
                        <td><?php echo $row['hours']; ?> hrs</td>
                        <td class="revenue-amount">UGX<?php echo number_format($row['revenue'],); ?></td>
                        <td>
                            <span class="stat-change positive">
                                <i class="fas fa-arrow-up"></i> +5.2%
                            </span>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Revenue Chart
        const ctx1 = document.getElementById('revenueChart').getContext('2d');
        new Chart(ctx1, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($dates); ?>,
                datasets: [{
                    label: 'Revenue ($)',
                    data: <?php echo json_encode($revenues); ?>,
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            display: true,
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // Distribution Chart
        const ctx2 = document.getElementById('distributionChart').getContext('2d');
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: ['Today', 'This Week', 'This Month', 'Total'],
                datasets: [{
                    data: [
                        <?php echo $today; ?>,
                        <?php echo $week; ?>,
                        <?php echo $month; ?>,
                        <?php echo $total; ?>
                    ],
                    backgroundColor: [
                        '#667eea',
                        '#10b981',
                        '#fbbf24',
                        '#ff6b6b'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                cutout: '70%'
            }
        });
    </script>
</body>
</html>