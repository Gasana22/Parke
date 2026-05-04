<?php
require_once 'db.php';
require_once 'auth.php';

$location_id = $_SESSION['location_id'];
$price_per_hour = $_SESSION['price_per_hour'];

// Total slots
$total_slots_result = $conn->query("SELECT COUNT(*) as total FROM parking_slots WHERE location_id = $location_id");
$total_slots = $total_slots_result->fetch_assoc()['total'];

// Occupied slots
$occupied_slots_result = $conn->query("
    SELECT COUNT(*) as occupied 
    FROM bookings 
    WHERE location_id = $location_id AND end_time IS NULL
");
$occupied_slots = $occupied_slots_result->fetch_assoc()['occupied'];

// Available slots
$available_slots = $total_slots - $occupied_slots;

// Today's revenue
$today = date('Y-m-d');
$revenue_result = $conn->query("
    SELECT SUM(total_cost) as revenue 
    FROM bookings 
    WHERE location_id = $location_id AND DATE(start_time) = '$today' AND payment_status='paid'
");
$revenue = $revenue_result->fetch_assoc()['revenue'] ?? 0;

// Active Vehicles
$active_result = $conn->query("
    SELECT b.id, b.vehicle_number, b.start_time, b.slot_id, s.slot_number
    FROM bookings b
    JOIN parking_slots s ON b.slot_id = s.id
    WHERE b.location_id = $location_id AND b.end_time IS NULL
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $_SESSION['location_name']; ?> Dashboard</title>
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

        .sidebar-header .user-info p i {
            font-size: 12px;
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
            position: relative;
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

        .nav-item .badge {
            margin-left: auto;
            background: #ff6b6b;
            color: white;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 20px;
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

        .recent-creations {
            margin-bottom: 32px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .section-header h2 {
            font-size: 18px;
            font-weight: 600;
            color: #1a1a2c;
        }

        .section-header a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }

        .cards-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.02);
            border: 1px solid #f0f0f5;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.05);
        }

        .stat-card .icon {
            width: 48px;
            height: 48px;
            background: #f0f0ff;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            color: #667eea;
            font-size: 20px;
        }

        .stat-card h3 {
            font-size: 14px;
            font-weight: 500;
            color: #6b7280;
            margin-bottom: 8px;
        }

        .stat-card .value {
            font-size: 28px;
            font-weight: 700;
            color: #1a1a2c;
        }

        .stat-card .change {
            font-size: 13px;
            color: #10b981;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .tools-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }

        .tool-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #f0f0f5;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .tool-card:hover {
            background: #f8f9fc;
            border-color: #667eea;
        }

        .tool-card .tool-icon {
            width: 40px;
            height: 40px;
            background: #f0f0ff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #667eea;
            font-size: 18px;
            margin-bottom: 12px;
        }

        .tool-card h4 {
            font-size: 15px;
            font-weight: 600;
            color: #1a1a2c;
            margin-bottom: 4px;
        }

        .tool-card p {
            font-size: 12px;
            color: #6b7280;
        }

        .activity-table {
            background: white;
            border-radius: 20px;
            padding: 24px;
            border: 1px solid #f0f0f5;
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

        .vehicle-info {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }

        .vehicle-info i {
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

        .timer {
            font-weight: 500;
            color: #667eea;
        }

        .cost {
            font-weight: 600;
            color: #1a1a2c;
        }

        .exit-btn {
            background: #ff6b6b;
            color: white;
            border: none;
            padding: 6px 16px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
        }

        .exit-btn:hover {
            background: #ff5252;
            transform: translateY(-2px);
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 48px;
            color: #e0e0f0;
            margin-bottom: 16px;
        }

        @media (max-width: 1200px) {
            .cards-grid, .tools-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                display: none;
            }
            .cards-grid, .tools-grid {
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
                <h3><?php echo $_SESSION['location_name']; ?></h3>
                <p><i class="fas fa-circle" style="color: #10b981; font-size: 8px;"></i> Online</p>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Navigation</div>
            <a href="location_dashboard.php" class="nav-item active">
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
            <a href="revenue_reports.php" class="nav-item">
                <i class="fas fa-chart-bar"></i>
                Revenue
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-credit-card"></i>
                Wallet
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
                <h1>Welcome back, <?php echo $_SESSION['location_name']; ?>!</h1>
                <p>Here's what's happening with your parking location today</p>
            </div>
            <div class="avatar-large">
                <i class="fas fa-parking"></i>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="cards-grid">
            <div class="stat-card">
                <div class="icon">
                    <i class="fas fa-layer-group"></i>
                </div>
                <h3>Total Slots</h3>
                <div class="value"><?php echo $total_slots; ?></div>
                <div class="change">
                    <i class="fas fa-arrow-up"></i> All slots
                </div>
            </div>

            <div class="stat-card">
                <div class="icon">
                    <i class="fas fa-car"></i>
                </div>
                <h3>Occupied Slots</h3>
                <div class="value"><?php echo $occupied_slots; ?></div>
                <div class="change">
                    <i class="fas fa-arrow-up"></i> Currently parked
                </div>
            </div>

            <div class="stat-card">
                <div class="icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h3>Available Slots</h3>
                <div class="value"><?php echo $available_slots; ?></div>
                <div class="change">
                    <i class="fas fa-arrow-down"></i> Ready to park
                </div>
            </div>

            <div class="stat-card">
                <div class="icon">
                    <i class="fas fa-coins"></i>
                </div>
                <h3>Today's Revenue</h3>
                <div class="value">UGX <?php echo number_format($revenue); ?></div>
                <div class="change">
                    <i class="fas fa-arrow-up"></i> +12.5%
                </div>
            </div>
        </div>

        <!-- Recent Creations Section -->
        <div class="recent-creations">
            <div class="section-header">
                <h2>Recent Creations</h2>
                <a href="#">See all <i class="fas fa-arrow-right"></i></a>
            </div>

            <div class="tools-grid">
                <div class="tool-card" onclick="window.location.href='slot_grid.php'">
                    <div class="tool-icon">
                        <i class="fas fa-th"></i>
                    </div>
                    <h4>URL to Video</h4>
                    <p>Turn URL into viewing video ads</p>
                </div>

                <div class="tool-card" onclick="window.location.href='recent_activity.php'">
                    <div class="tool-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h4>More tools</h4>
                    <p>Additional features</p>
                </div>

                <div class="tool-card" onclick="window.location.href='profile.php'">
                    <div class="tool-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <h4>Create Custom Avatar</h4>
                    <p>Customize your profile</p>
                </div>

                <div class="tool-card" onclick="window.location.href='revenue_reports.php'">
                    <div class="tool-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h4>Text to Video</h4>
                    <p>Generate reports</p>
                </div>
            </div>
        </div>

        <!-- Currently Parked Vehicles -->
        <div class="activity-table">
            <div class="section-header" style="margin-bottom: 20px;">
                <h2>Currently Parked Vehicles</h2>
                <a href="recent_activity.php">View all activity</a>
            </div>

            <?php if ($active_result->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Vehicle</th>
                        <th>Slot</th>
                        <th>Entry Time</th>
                        <th>Duration</th>
                        <th>Current Cost</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $active_result->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <div class="vehicle-info">
                                <i class="fas fa-car"></i>
                                <?php echo $row['vehicle_number']; ?>
                            </div>
                        </td>
                        <td>
                            <span class="slot-badge">
                                Slot <?php echo $row['slot_number']; ?>
                            </span>
                        </td>
                        <td><?php echo date('H:i:s', strtotime($row['start_time'])); ?></td>
                        <td class="timer" data-start="<?php echo $row['start_time']; ?>"></td>
                        <td class="cost" data-start="<?php echo $row['start_time']; ?>"></td>
                        <td>
                            <form method="POST" action="exit_vehicle.php" style="display: inline;">
                                <input type="hidden" name="booking_id" value="<?php echo $row['id']; ?>">
                                <input type="hidden" name="slot_id" value="<?php echo $row['slot_id']; ?>">
                                <button type="submit" class="exit-btn">
                                    <i class="fas fa-sign-out-alt"></i>
                                    Exit
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-parking"></i>
                <p>No vehicles currently parked</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    const pricePerHour = <?php echo $price_per_hour; ?>;

    function updateTimers() {
        const timers = document.querySelectorAll('.timer');
        const costs = document.querySelectorAll('.cost');

        timers.forEach((timer, index) => {
            const startTime = new Date(timer.dataset.start).getTime();
            const now = new Date().getTime();
            const diff = now - startTime;

            const hours = Math.floor(diff / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);

            timer.innerHTML = `${hours}h ${minutes}m ${seconds}s`;

            const totalHours = diff / (1000 * 60 * 60);
            const currentCost = (totalHours * pricePerHour).toFixed(0);

            costs[index].innerHTML = "UGX " + Number(currentCost).toLocaleString();
        });
    }

    setInterval(updateTimers, 1000);
    updateTimers();
    </script>
</body>
</html>