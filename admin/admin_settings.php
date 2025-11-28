<?php
session_start();
include '../config.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_settings'])) {
        foreach ($_POST['settings'] as $key => $value) {
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?) 
                                  ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?, updated_at = NOW()");
            $stmt->execute([$key, $value, $_SESSION['admin_id'], $value, $_SESSION['admin_id']]);
        }
        
        $message = "System settings updated successfully!";
    } elseif (isset($_POST['update_profile'])) {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $full_name = trim($_POST['full_name']);
        $password = $_POST['password'];
        
        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE admins SET username = ?, email = ?, full_name = ?, password = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$username, $email, $full_name, $hashed_password, $_SESSION['admin_id']]);
        } else {
            $stmt = $pdo->prepare("UPDATE admins SET username = ?, email = ?, full_name = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$username, $email, $full_name, $_SESSION['admin_id']]);
        }
        
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_name'] = $full_name;
        
        $message = "Profile updated successfully!";
    }
    
    $log_stmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action, description, ip_address, user_agent) VALUES (?, 'settings_update', ?, ?, ?)");
    $log_stmt->execute([$_SESSION['admin_id'], "System settings updated", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
    
    header("Location: admin_settings.php?success=" . urlencode($message));
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$admin_data = $stmt->fetch();

$stmt = $pdo->query("SELECT * FROM system_settings");
$settings_data = $stmt->fetchAll();

$settings = [];
foreach ($settings_data as $setting) {
    $settings[$setting['setting_key']] = $setting['setting_value'];
}

$default_settings = [
    'system_name' => 'Parke Parking System',
    'currency' => 'UGX',
    'timezone' => 'Africa/Kampala',
    'max_reservation_hours' => '24',
    'cancellation_fee_percent' => '10',
    'tax_rate_percent' => '18',
    'support_email' => 'support@parke.com',
    'support_phone' => '+256 700 000000'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - Parke</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }
        
        :root {
            --primary: #4CAF50;
            --secondary: #2196F3;
            --danger: #f44336;
            --warning: #ff9800;
            --info: #17a2b8;
            --dark: #343a40;
            --light: #f8f9fa;
        }
        
        body {
            background: #f5f5f5;
            color: #333;
        }
        
        .admin-header {
            background: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .admin-nav {
            background: var(--dark);
            color: white;
            padding: 1rem 2rem;
        }
        
        .nav-links {
            display: flex;
            gap: 2rem;
            list-style: none;
        }
        
        .nav-links a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            transition: background 0.3s;
        }
        
        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255,255,255,0.1);
        }
        
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .page-header {
            margin-bottom: 2rem;
        }
        
        .page-header h1 {
            color: var(--dark);
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 0.9rem;
            font-weight: bold;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: #45a049;
        }
        
        .card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-weight: bold;
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e5e9;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        
        .logout-btn {
            background: var(--danger);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        
        .logout-btn:hover {
            background: #d32f2f;
        }
        
        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        
        .tab-navigation {
            display: flex;
            gap: 0;
            border-bottom: 2px solid #e1e5e9;
            margin-bottom: 1.5rem;
        }
        
        .tab-button {
            padding: 1rem 2rem;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            font-weight: bold;
            color: #666;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }
        
        .tab-button.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .setting-description {
            font-size: 0.8rem;
            color: #666;
            margin-top: 0.25rem;
        }
        
        @media (max-width: 768px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-header">
        <div class="brand">
            <h1>Parke Admin Dashboard</h1>
        </div>
        <div class="user-info">
            <div class="user-avatar">
                <?php echo strtoupper(substr($_SESSION['admin_name'], 0, 1)); ?>
            </div>
            <div>
                <strong><?php echo htmlspecialchars($_SESSION['admin_name']); ?></strong>
                <div style="font-size: 0.8rem; color: #666;">Administrator</div>
            </div>
            <a href="admin_logout.php" class="logout-btn">Logout</a>
        </div>
    </div>
    
    <nav class="admin-nav">
        <ul class="nav-links">
            <li><a href="admin_dashboard.php">📊 Dashboard</a></li>
            <li><a href="admin_parking_lots.php">🅿️ Parking Lots</a></li>
            <li><a href="admin_drivers.php">👥 Drivers</a></li>
            <li><a href="admin_operators.php">🏢 Operators</a></li>
            <li><a href="admin_reservations.php">📋 Reservations</a></li>
            <li><a href="admin_reports.php">📈 Reports</a></li>
            <li><a href="admin_settings.php" class="active">⚙️ Settings</a></li>
        </ul>
    </nav>
    
    <div class="container">
        <div class="page-header">
            <h1>⚙️ System Settings</h1>
        </div>
        
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($_GET['success']); ?></div>
        <?php endif; ?>
        
        <div class="tab-navigation">
            <button class="tab-button active" onclick="openTab('system-settings')">System Settings</button>
            <button class="tab-button" onclick="openTab('profile-settings')">Profile Settings</button>
        </div>
        
        <div id="system-settings" class="tab-content active">
            <div class="card">
                <h2>System Configuration</h2>
                <form method="POST" action="">
                    <input type="hidden" name="update_settings" value="1">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="system_name">System Name</label>
                            <input type="text" id="system_name" name="settings[system_name]" class="form-control" 
                                   value="<?php echo htmlspecialchars($settings['system_name'] ?? $default_settings['system_name']); ?>">
                            <div class="setting-description">The name of your parking management system</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="currency">Currency</label>
                            <select id="currency" name="settings[currency]" class="form-control">
                                <option value="UGX" <?php echo ($settings['currency'] ?? $default_settings['currency']) === 'UGX' ? 'selected' : ''; ?>>UGX - Ugandan Shilling</option>
                                <option value="USD" <?php echo ($settings['currency'] ?? $default_settings['currency']) === 'USD' ? 'selected' : ''; ?>>USD - US Dollar</option>
                                <option value="EUR" <?php echo ($settings['currency'] ?? $default_settings['currency']) === 'EUR' ? 'selected' : ''; ?>>EUR - Euro</option>
                            </select>
                            <div class="setting-description">Default currency for pricing</div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="timezone">Timezone</label>
                            <select id="timezone" name="settings[timezone]" class="form-control">
                                <option value="Africa/Kampala" <?php echo ($settings['timezone'] ?? $default_settings['timezone']) === 'Africa/Kampala' ? 'selected' : ''; ?>>Africa/Kampala</option>
                                <option value="UTC" <?php echo ($settings['timezone'] ?? $default_settings['timezone']) === 'UTC' ? 'selected' : ''; ?>>UTC</option>
                            </select>
                            <div class="setting-description">System timezone</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="max_reservation_hours">Max Reservation Hours</label>
                            <input type="number" id="max_reservation_hours" name="settings[max_reservation_hours]" class="form-control" 
                                   value="<?php echo $settings['max_reservation_hours'] ?? $default_settings['max_reservation_hours']; ?>" min="1" max="168">
                            <div class="setting-description">Maximum allowed reservation duration in hours</div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="cancellation_fee_percent">Cancellation Fee (%)</label>
                            <input type="number" id="cancellation_fee_percent" name="settings[cancellation_fee_percent]" class="form-control" 
                                   value="<?php echo $settings['cancellation_fee_percent'] ?? $default_settings['cancellation_fee_percent']; ?>" min="0" max="100" step="0.1">
                            <div class="setting-description">Percentage charged for cancellations</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="tax_rate_percent">Tax Rate (%)</label>
                            <input type="number" id="tax_rate_percent" name="settings[tax_rate_percent]" class="form-control" 
                                   value="<?php echo $settings['tax_rate_percent'] ?? $default_settings['tax_rate_percent']; ?>" min="0" max="100" step="0.1">
                            <div class="setting-description">Tax rate applied to reservations</div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="support_email">Support Email</label>
                            <input type="email" id="support_email" name="settings[support_email]" class="form-control" 
                                   value="<?php echo htmlspecialchars($settings['support_email'] ?? $default_settings['support_email']); ?>">
                            <div class="setting-description">Customer support email address</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="support_phone">Support Phone</label>
                            <input type="tel" id="support_phone" name="settings[support_phone]" class="form-control" 
                                   value="<?php echo htmlspecialchars($settings['support_phone'] ?? $default_settings['support_phone']); ?>">
                            <div class="setting-description">Customer support phone number</div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Save System Settings</button>
                </form>
            </div>
        </div>
        
        <div id="profile-settings" class="tab-content">
            <div class="card">
                <h2>Admin Profile</h2>
                <form method="POST" action="">
                    <input type="hidden" name="update_profile" value="1">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" class="form-control" 
                                   value="<?php echo htmlspecialchars($admin_data['username']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" class="form-control" 
                                   value="<?php echo htmlspecialchars($admin_data['email']); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" class="form-control" 
                               value="<?php echo htmlspecialchars($admin_data['full_name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">New Password</label>
                        <input type="password" id="password" name="password" class="form-control" 
                               placeholder="Leave blank to keep current password">
                        <div class="setting-description">Minimum 8 characters</div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Update Profile</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openTab(tabName) {
            const tabContents = document.getElementsByClassName('tab-content');
            for (let i = 0; i < tabContents.length; i++) {
                tabContents[i].classList.remove('active');
            }
            
            const tabButtons = document.getElementsByClassName('tab-button');
            for (let i = 0; i < tabButtons.length; i++) {
                tabButtons[i].classList.remove('active');
            }
            
            document.getElementById(tabName).classList.add('active');
            event.currentTarget.classList.add('active');
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const password = document.getElementById('password');
                    if (password && password.value && password.value.length < 8) {
                        e.preventDefault();
                        alert('Password must be at least 8 characters long');
                        password.focus();
                    }
                });
            });
        });
    </script>
</body>
</html>