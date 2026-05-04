<?php
require_once 'db.php';
require_once 'auth.php';

$location_id = $_SESSION['location_id'];

$stmt = $conn->prepare("
    SELECT b.*, s.slot_number 
    FROM bookings b
    JOIN parking_slots s ON b.slot_id = s.id
    WHERE b.location_id = ?
    AND b.end_time IS NOT NULL
    ORDER BY b.end_time DESC
    LIMIT 50
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
    <title>Recent Activity</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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

        /* Filters */
        .filters-section {
            background: white;
            border-radius: 16px;
            padding: 16px 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid #f0f0f5;
        }

        .filter-tabs {
            display: flex;
            gap: 8px;
        }

        .filter-tab {
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #6b7280;
        }

        .filter-tab:hover {
            background: #f0f0ff;
            color: #667eea;
        }

        .filter-tab.active {
            background: #667eea;
            color: white;
        }

        .search-box {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f8f9fc;
            padding: 8px 16px;
            border-radius: 30px;
            border: 1px solid #f0f0f5;
        }

        .search-box i {
            color: #6b7280;
            font-size: 14px;
        }

        .search-box input {
            border: none;
            background: transparent;
            outline: none;
            font-size: 13px;
            width: 200px;
        }

        /* Activity Table */
        .activity-table {
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

        .table-header h2 {
            font-size: 18px;
            font-weight: 600;
            color: #1a1a2c;
        }

        .export-btn {
            background: #f8f9fc;
            border: 1px solid #f0f0f5;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            color: #1a1a2c;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .export-btn:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
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
            padding: 16px 12px;
            border-bottom: 1px solid #f0f0f5;
            font-size: 14px;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background: #f8f9fc;
        }

        .vehicle-cell {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }

        .vehicle-cell i {
            color: #667eea;
        }

        .slot-badge {
            background: #f0f0ff;
            color: #667eea;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }

        .status-badge.paid {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.pending {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-badge.cancelled {
            background: #fef3c7;
            color: #92400e;
        }

        .amount {
            font-weight: 600;
            color: #1a1a2c;
        }

        .time-cell {
            font-family: monospace;
            color: #6b7280;
        }

        .pagination {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 8px;
            margin-top: 24px;
        }

        .page-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #f0f0f5;
            background: white;
            color: #1a1a2c;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .page-btn:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .page-btn.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .empty-state {
            text-align: center;
            padding: 60px;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 48px;
            color: #e0e0f0;
            margin-bottom: 16px;
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
            <a href="recent_activity.php" class="nav-item active">
                <i class="fas fa-history"></i>
                Activity
            </a>
            <a href="revenue_reports.php" class="nav-item">
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
                <h1>Recent Activity</h1>
                <p>Track all parking transactions and history</p>
            </div>
            <div class="avatar-large">
                <i class="fas fa-history"></i>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-section">
            <div class="filter-tabs">
                <span class="filter-tab active">All</span>
                <span class="filter-tab">Today</span>
                <span class="filter-tab">This Week</span>
                <span class="filter-tab">This Month</span>
                <span class="filter-tab">Paid</span>
                <span class="filter-tab">Pending</span>
            </div>
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search vehicles...">
            </div>
        </div>

        <!-- Activity Table -->
        <div class="activity-table">
            <div class="table-header">
                <h2>Transaction History</h2>
                <button class="export-btn">
                    <i class="fas fa-download"></i>
                    Export CSV
                </button>
            </div>

            <?php if ($result->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Vehicle</th>
                        <th>Slot</th>
                        <th>Entry Time</th>
                        <th>Exit Time</th>
                        <th>Duration</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $result->fetch_assoc()): 
                        $start = strtotime($row['start_time']);
                        $end = strtotime($row['end_time']);
                        $diff = $end - $start;

                        $hours = floor($diff / 3600);
                        $minutes = floor(($diff % 3600) / 60);
                    ?>
                    <tr>
                        <td>
                            <div class="vehicle-cell">
                                <i class="fas fa-car"></i>
                                <?php echo $row['vehicle_number']; ?>
                            </div>
                        </td>
                        <td>
                            <span class="slot-badge">
                                <i class="fas fa-map-pin"></i>
                                Slot <?php echo $row['slot_number']; ?>
                            </span>
                        </td>
                        <td class="time-cell"><?php echo date('H:i:s', $start); ?></td>
                        <td class="time-cell"><?php echo date('H:i:s', $end); ?></td>
                        <td><?php echo $hours . "h " . $minutes . "m"; ?></td>
                        <td class="amount">UGX<?php echo number_format($row['total_cost'],); ?></td>
                        <td>
                            <span class="status-badge <?php echo $row['payment_status']; ?>">
                                <?php echo ucfirst($row['payment_status']); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <div class="pagination">
                <button class="page-btn"><i class="fas fa-chevron-left"></i></button>
                <button class="page-btn active">1</button>
                <button class="page-btn">2</button>
                <button class="page-btn">3</button>
                <button class="page-btn">4</button>
                <button class="page-btn">5</button>
                <button class="page-btn"><i class="fas fa-chevron-right"></i></button>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-history"></i>
                <p>No recent activity found</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>