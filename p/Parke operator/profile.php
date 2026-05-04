<?php
require_once 'db.php';
require_once 'auth.php';

$location_id = $_SESSION['location_id'];
$message = '';
$error = '';

// Get current location details
$stmt = $conn->prepare("SELECT * FROM parking_locations WHERE id = ?");
$stmt->bind_param("i", $location_id);
$stmt->execute();
$result = $stmt->get_result();
$location = $result->fetch_assoc();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['update_profile'])) {
        $name = trim($_POST['name']);
        $address = trim($_POST['address']);
        $city = trim($_POST['city']);
        $state = trim($_POST['state']);
        $price_per_hour = floatval($_POST['price_per_hour']);
        $username = trim($_POST['username']);
        $contact_phone = trim($_POST['contact_phone']);
        $contact_email = trim($_POST['contact_email']);
        $operating_hours = trim($_POST['operating_hours']);
        $amenities = trim($_POST['amenities']);
        $security_info = trim($_POST['security_info']);
        
        $update_stmt = $conn->prepare("
            UPDATE parking_locations 
            SET name = ?, address = ?, city = ?, state = ?, 
                price_per_hour = ?, location_username = ?,
                contact_phone = ?, contact_email = ?, 
                operating_hours = ?, amenities = ?, security_info = ?
            WHERE id = ?
        ");
        $update_stmt->bind_param("ssssdssssssi", 
            $name, $address, $city, $state, $price_per_hour, $username,
            $contact_phone, $contact_email, $operating_hours, $amenities, 
            $security_info, $location_id
        );
        
        if ($update_stmt->execute()) {
            $_SESSION['location_name'] = $name;
            $_SESSION['price_per_hour'] = $price_per_hour;
            $message = "Profile updated successfully!";
            
            // Refresh location data
            $stmt->execute();
            $location = $stmt->get_result()->fetch_assoc();
        } else {
            $error = "Failed to update profile.";
        }
    }
    
    // Handle password change
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if (password_verify($current_password, $location['location_password'])) {
            if ($new_password === $confirm_password) {
                if (strlen($new_password) >= 6) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $password_stmt = $conn->prepare("UPDATE parking_locations SET location_password = ? WHERE id = ?");
                    $password_stmt->bind_param("si", $hashed_password, $location_id);
                    
                    if ($password_stmt->execute()) {
                        $message = "Password changed successfully!";
                    } else {
                        $error = "Failed to change password.";
                    }
                } else {
                    $error = "Password must be at least 6 characters long.";
                }
            } else {
                $error = "New passwords do not match.";
            }
        } else {
            $error = "Current password is incorrect.";
        }
    }
}

// Get statistics
$stats = [];
$transactions_stmt = $conn->prepare("SELECT COUNT(*) as count FROM bookings WHERE location_id = ?");
$transactions_stmt->bind_param("i", $location_id);
$transactions_stmt->execute();
$stats['total_transactions'] = $transactions_stmt->get_result()->fetch_assoc()['count'];

$revenue_stmt = $conn->prepare("SELECT SUM(total_cost) as total FROM bookings WHERE location_id = ? AND payment_status = 'paid'");
$revenue_stmt->bind_param("i", $location_id);
$revenue_stmt->execute();
$stats['total_revenue'] = $revenue_stmt->get_result()->fetch_assoc()['total'] ?? 0;

$slots_count = $conn->query("SELECT COUNT(*) as count FROM parking_slots WHERE location_id = $location_id")->fetch_assoc()['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - <?php echo htmlspecialchars($_SESSION['location_name']); ?></title>
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

        /* Profile Header */
        .profile-header {
            background: white;
            border-radius: 20px;
            padding: 32px;
            margin-bottom: 24px;
            border: 1px solid #f0f0f5;
            display: flex;
            align-items: center;
            gap: 32px;
        }

        .profile-avatar-large {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            color: white;
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .profile-info h2 {
            font-size: 28px;
            font-weight: 700;
            color: #1a1a2c;
            margin-bottom: 8px;
        }

        .profile-meta {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }

        .profile-meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #6b7280;
            font-size: 14px;
        }

        .profile-meta-item i {
            color: #667eea;
        }

        .badge {
            background: #d1fae5;
            color: #065f46;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #f0f0f5;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            background: #f0f0ff;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #667eea;
            font-size: 20px;
        }

        .stat-details h3 {
            font-size: 14px;
            font-weight: 500;
            color: #6b7280;
            margin-bottom: 4px;
        }

        .stat-details p {
            font-size: 20px;
            font-weight: 700;
            color: #1a1a2c;
        }

        /* Profile Content */
        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .profile-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            border: 1px solid #f0f0f5;
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid #f0f0f5;
        }

        .card-header i {
            font-size: 20px;
            color: #667eea;
        }

        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: #1a1a2c;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 500;
            color: #6b7280;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
            font-size: 14px;
        }

        .input-wrapper input,
        .input-wrapper textarea,
        .input-wrapper select {
            width: 100%;
            padding: 10px 10px 10px 36px;
            border: 1px solid #f0f0f5;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
        }

        .input-wrapper textarea {
            padding-top: 10px;
            min-height: 80px;
        }

        .input-wrapper input:focus,
        .input-wrapper textarea:focus,
        .input-wrapper select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .alert.success {
            background: #d1fae5;
            color: #065f46;
        }

        .alert.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .form-actions {
            margin-top: 24px;
            display: flex;
            justify-content: flex-end;
        }

        /* Info Display */
        .info-row {
            display: flex;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f5;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            width: 140px;
            font-weight: 500;
            color: #6b7280;
            font-size: 14px;
        }

        .info-value {
            flex: 1;
            color: #1a1a2c;
            font-weight: 500;
            font-size: 14px;
        }

        .access-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            background: #f0f0ff;
            color: #667eea;
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
            <a href="profile.php" class="nav-item active">
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
                <h1>Profile Settings</h1>
                <p>Manage your account and location information</p>
            </div>
            <div class="avatar-large">
                <i class="fas fa-user-circle"></i>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
        <div class="alert success">
            <i class="fas fa-check-circle"></i>
            <?php echo $message; ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert error">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo $error; ?>
        </div>
        <?php endif; ?>

        <!-- Profile Header -->
        <div class="profile-header">
            <div class="profile-avatar-large">
                <i class="fas fa-parking"></i>
            </div>
            <div>
                <h2><?php echo htmlspecialchars($location['name']); ?></h2>
                <div class="profile-meta">
                    <span class="profile-meta-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($location['city'] ?? 'N/A'); ?>, <?php echo htmlspecialchars($location['state'] ?? 'N/A'); ?>
                    </span>
                    <span class="profile-meta-item">
                        <i class="fas fa-phone"></i>
                        <?php echo htmlspecialchars($location['contact_phone'] ?? 'Not set'); ?>
                    </span>
                    <span class="profile-meta-item">
                        <i class="fas fa-envelope"></i>
                        <?php echo htmlspecialchars($location['contact_email'] ?? 'Not set'); ?>
                    </span>
                    <span class="badge">
                        <i class="fas fa-check-circle"></i>
                        Active
                    </span>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="stat-details">
                    <h3>Total Transactions</h3>
                    <p><?php echo number_format($stats['total_transactions']); ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stat-details">
                    <h3>Total Revenue</h3>
                    <p>UGX<?php echo number_format($stats['total_revenue'], ); ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-layer-group"></i>
                </div>
                <div class="stat-details">
                    <h3>Total Slots</h3>
                    <p><?php echo $slots_count; ?></p>
                </div>
            </div>
        </div>

        <!-- Profile Content -->
        <div class="profile-grid">
            <!-- Edit Profile Form -->
            <div class="profile-card">
                <div class="card-header">
                    <i class="fas fa-edit"></i>
                    <h3>Edit Profile Information</h3>
                </div>
                <form method="POST">
                    <div class="form-group">
                        <label>Location Name</label>
                        <div class="input-wrapper">
                            <i class="fas fa-building"></i>
                            <input type="text" name="name" value="<?php echo htmlspecialchars($location['name'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Address</label>
                        <div class="input-wrapper">
                            <i class="fas fa-map-marker-alt"></i>
                            <input type="text" name="address" value="<?php echo htmlspecialchars($location['address'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>City</label>
                        <div class="input-wrapper">
                            <i class="fas fa-city"></i>
                            <input type="text" name="city" value="<?php echo htmlspecialchars($location['city'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>State</label>
                        <div class="input-wrapper">
                            <i class="fas fa-map"></i>
                            <input type="text" name="state" value="<?php echo htmlspecialchars($location['state'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Contact Phone</label>
                        <div class="input-wrapper">
                            <i class="fas fa-phone"></i>
                            <input type="tel" name="contact_phone" value="<?php echo htmlspecialchars($location['contact_phone'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Contact Email</label>
                        <div class="input-wrapper">
                            <i class="fas fa-envelope"></i>
                            <input type="email" name="contact_email" value="<?php echo htmlspecialchars($location['contact_email'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Operating Hours</label>
                        <div class="input-wrapper">
                            <i class="fas fa-clock"></i>
                            <input type="text" name="operating_hours" value="<?php echo htmlspecialchars($location['operating_hours'] ?? '24/7'); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Price per Hour (UGX)</label>
                        <div class="input-wrapper">
                            <i class="fas fa-tag"></i>
                            <input type="number" step="0.01" name="price_per_hour" value="<?php echo $location['price_per_hour']; ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Username</label>
                        <div class="input-wrapper">
                            <i class="fas fa-user"></i>
                            <input type="text" name="username" value="<?php echo htmlspecialchars($location['location_username'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Amenities</label>
                        <div class="input-wrapper">
                            <i class="fas fa-gym"></i>
                            <textarea name="amenities" placeholder="e.g., CCTV, Covered parking, EV charging"><?php echo htmlspecialchars($location['amenities'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Security Information</label>
                        <div class="input-wrapper">
                            <i class="fas fa-shield-alt"></i>
                            <textarea name="security_info" placeholder="e.g., 24/7 security, Guard on duty"><?php echo htmlspecialchars($location['security_info'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="update_profile" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <!-- Change Password & Info -->
            <div>
                <div class="profile-card" style="margin-bottom: 20px;">
                    <div class="card-header">
                        <i class="fas fa-lock"></i>
                        <h3>Change Password</h3>
                    </div>
                    <form method="POST">
                        <div class="form-group">
                            <label>Current Password</label>
                            <div class="input-wrapper">
                                <i class="fas fa-lock"></i>
                                <input type="password" name="current_password" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>New Password</label>
                            <div class="input-wrapper">
                                <i class="fas fa-key"></i>
                                <input type="password" name="new_password" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Confirm New Password</label>
                            <div class="input-wrapper">
                                <i class="fas fa-check-circle"></i>
                                <input type="password" name="confirm_password" required>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="change_password" class="btn btn-primary">
                                <i class="fas fa-sync-alt"></i>
                                Update Password
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Location Details -->
                <div class="profile-card">
                    <div class="card-header">
                        <i class="fas fa-info-circle"></i>
                        <h3>Location Details</h3>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Location ID</span>
                        <span class="info-value">#<?php echo str_pad($location['id'], 5, '0', STR_PAD_LEFT); ?></span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Coordinates</span>
                        <span class="info-value"><?php echo $location['latitude'] ?? '0.0000'; ?>, <?php echo $location['longitude'] ?? '0.0000'; ?></span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Available Slots</span>
                        <span class="info-value"><?php echo $location['available_slots'] ?? 0; ?> / <?php echo $location['total_slots'] ?? 0; ?></span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Access Permissions</span>
                        <span class="info-value">
                            <div style="display: flex; gap: 8px;">
                                <?php if($location['has_camera_access']): ?>
                                <span class="access-badge">
                                    <i class="fas fa-video"></i> Camera
                                </span>
                                <?php endif; ?>
                                <?php if($location['has_gate_access']): ?>
                                <span class="access-badge">
                                    <i class="fas fa-gate"></i> Gate
                                </span>
                                <?php endif; ?>
                            </div>
                        </span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Member Since</span>
                        <span class="info-value"><?php echo date('F j, Y', strtotime($location['created_at'] ?? date('Y-m-d'))); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>