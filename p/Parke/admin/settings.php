<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$conn = getConnection();

// Create settings table if it doesn't exist
$conn->query("
    CREATE TABLE IF NOT EXISTS settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

// Handle settings update
if (isset($_POST['save_settings'])) {
    // Update system settings
    $settings = [
        'site_name' => $_POST['site_name'],
        'site_email' => $_POST['site_email'],
        'currency' => 'UGX',
        'booking_buffer' => (int)$_POST['booking_buffer'],
        'max_booking_days' => (int)$_POST['max_booking_days'],
        'tax_rate' => (float)$_POST['tax_rate'],
        'cancellation_hours' => (int)$_POST['cancellation_hours'],
        'auto_cancel_minutes' => (int)$_POST['auto_cancel_minutes'],
        'reminder_hours' => (int)$_POST['reminder_hours'],
        'max_login_attempts' => (int)$_POST['max_login_attempts'],
        'session_timeout' => (int)$_POST['session_timeout']
    ];
    
    foreach ($settings as $key => $value) {
        $escaped_value = $conn->real_escape_string($value);
        $conn->query("INSERT INTO settings (setting_key, setting_value) 
                     VALUES ('$key', '$escaped_value')
                     ON DUPLICATE KEY UPDATE setting_value = '$escaped_value'");
    }
    
    $message = "Settings updated successfully!";
    $message_type = "success";
}

// Get current settings
$settings_result = $conn->query("SELECT * FROM settings");
$current_settings = [];
while($setting = $settings_result->fetch_assoc()) {
    $current_settings[$setting['setting_key']] = $setting['setting_value'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - PARKE Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        
        .settings-tabs {
            display: flex;
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        
        .tab {
            padding: 15px 25px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            font-weight: 600;
            color: #666;
            transition: all 0.3s;
        }
        
        .tab:hover {
            color: #667eea;
        }
        
        .tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .settings-form {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .form-section {
            margin-bottom: 30px;
            padding-bottom: 30px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .form-section h3 {
            margin-bottom: 20px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
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
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 10px;
        }
        
        .checkbox-group label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: normal;
            cursor: pointer;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
        }
        
        .message {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 16px;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        @media (max-width: 768px) {
            .settings-tabs {
                flex-direction: column;
            }
            .tab {
                padding: 12px 15px;
                border-bottom: 1px solid #eee;
            }
            .form-row {
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
        <h1><i class="fas fa-cog"></i> System Settings</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['admin_name']); ?></span>
            <a href="dashboard.php" class="logout-btn"><i class="fas fa-arrow-left"></i> Dashboard</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
    
    <div class="container">
        <?php if (isset($message)): ?>
            <div class="message <?php echo $message_type; ?>">
                <i class="fas fa-check-circle"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <div class="settings-tabs">
            <div class="tab active" onclick="showTab('general')">
                <i class="fas fa-globe"></i> General
            </div>
            <div class="tab" onclick="showTab('booking')">
                <i class="fas fa-calendar-alt"></i> Booking
            </div>
            <div class="tab" onclick="showTab('notification')">
                <i class="fas fa-bell"></i> Notifications
            </div>
            <div class="tab" onclick="showTab('security')">
                <i class="fas fa-shield-alt"></i> Security
            </div>
        </div>
        
        <form method="POST" class="settings-form">
            <!-- General Settings -->
            <div id="general-tab" class="tab-content active">
                <div class="form-section">
                    <h3><i class="fas fa-globe"></i> General Settings</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="site_name">Site Name</label>
                            <input type="text" id="site_name" name="site_name" class="form-control"
                                   value="<?php echo $current_settings['site_name'] ?? 'PARKE Parking System'; ?>">
                        </div>
                        <div class="form-group">
                            <label for="site_email">Admin Email</label>
                            <input type="email" id="site_email" name="site_email" class="form-control"
                                   value="<?php echo $current_settings['site_email'] ?? 'admin@parke.com'; ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
    <label for="currency">Currency</label>
    <select id="currency" name="currency" class="form-control">
        <option value="UGX" selected>Uganda Shillings (UGX)</option>
        <option value="USD">USD ($)</option>
        <option value="EUR">EUR (€)</option>
        <option value="GBP">GBP (£)</option>
        <option value="KES">Kenya Shillings (KES)</option>
        <option value="TZS">Tanzania Shillings (TZS)</option>
        <option value="RWF">Rwanda Francs (RWF)</option>
    </select>
</div>
                    </div>
                </div>
            </div>
            
            <!-- Booking Settings -->
            <div id="booking-tab" class="tab-content">
                <div class="form-section">
                    <h3><i class="fas fa-calendar-alt"></i> Booking Settings</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="booking_buffer">Booking Buffer (minutes)</label>
                            <input type="number" id="booking_buffer" name="booking_buffer" class="form-control"
                                   value="<?php echo $current_settings['booking_buffer'] ?? 15; ?>" min="0">
                            <small style="color: #666;">Minimum time between booking start and current time</small>
                        </div>
                        <div class="form-group">
                            <label for="max_booking_days">Max Advance Booking (days)</label>
                            <input type="number" id="max_booking_days" name="max_booking_days" class="form-control"
                                   value="<?php echo $current_settings['max_booking_days'] ?? 30; ?>" min="1">
                            <small style="color: #666;">Maximum days in advance a booking can be made</small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="cancellation_hours">Cancellation Window (hours)</label>
                            <input type="number" id="cancellation_hours" name="cancellation_hours" class="form-control"
                                   value="<?php echo $current_settings['cancellation_hours'] ?? 2; ?>" min="0">
                            <small style="color: #666;">Hours before booking when cancellation is allowed</small>
                        </div>
                        <div class="form-group">
                            <label for="auto_cancel_minutes">Auto-cancel Time (minutes)</label>
                            <input type="number" id="auto_cancel_minutes" name="auto_cancel_minutes" class="form-control"
                                   value="<?php echo $current_settings['auto_cancel_minutes'] ?? 15; ?>" min="0">
                            <small style="color: #666;">Auto-cancel unpaid bookings after X minutes</small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="tax_rate">Tax Rate (%)</label>
                            <input type="number" step="0.01" id="tax_rate" name="tax_rate" class="form-control"
                                   value="<?php echo $current_settings['tax_rate'] ?? 8.5; ?>" min="0" max="100">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Notification Settings -->
            <div id="notification-tab" class="tab-content">
                <div class="form-section">
                    <h3><i class="fas fa-bell"></i> Notification Settings</h3>
                    <div class="form-group">
                        <label>Notification Methods</label>
                        <div class="checkbox-group">
                            <label>
                                <input type="checkbox" name="email_notifications" value="1" checked> 
                                Email Notifications
                            </label>
                            <label>
                                <input type="checkbox" name="sms_notifications" value="1"> 
                                SMS Notifications (requires SMS gateway)
                            </label>
                            <label>
                                <input type="checkbox" name="push_notifications" value="1"> 
                                Push Notifications
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="reminder_hours">Booking Reminder (hours before)</label>
                            <input type="number" id="reminder_hours" name="reminder_hours" class="form-control" 
                                   value="<?php echo $current_settings['reminder_hours'] ?? 24; ?>" min="0">
                            <small style="color: #666;">Send reminder notification before booking starts</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Security Settings -->
            <div id="security-tab" class="tab-content">
                <div class="form-section">
                    <h3><i class="fas fa-shield-alt"></i> Security Settings</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="max_login_attempts">Max Login Attempts</label>
                            <input type="number" id="max_login_attempts" name="max_login_attempts" class="form-control" 
                                   value="<?php echo $current_settings['max_login_attempts'] ?? 5; ?>" min="1">
                            <small style="color: #666;">Maximum failed login attempts before lockout</small>
                        </div>
                        <div class="form-group">
                            <label for="session_timeout">Session Timeout (minutes)</label>
                            <input type="number" id="session_timeout" name="session_timeout" class="form-control" 
                                   value="<?php echo $current_settings['session_timeout'] ?? 30; ?>" min="1">
                            <small style="color: #666;">Inactivity timeout for admin sessions</small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="password_expiry">Password Expiry (days)</label>
                            <input type="number" id="password_expiry" name="password_expiry" class="form-control" 
                                   value="90" min="1">
                            <small style="color: #666;">Days before password expires (0 = never)</small>
                        </div>
                        <div class="form-group">
                            <label>Password Requirements</label>
                            <div class="checkbox-group">
                                <label>
                                    <input type="checkbox" name="require_uppercase" value="1" checked> 
                                    Require uppercase letters
                                </label>
                                <label>
                                    <input type="checkbox" name="require_numbers" value="1" checked> 
                                    Require numbers
                                </label>
                                <label>
                                    <input type="checkbox" name="require_special" value="1"> 
                                    Require special characters
                                </label>
                                <label>
                                    <input type="checkbox" name="min_password_length" value="8" checked> 
                                    Minimum 8 characters
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div style="text-align: right; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e9ecef;">
                <button type="submit" name="save_settings" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save All Settings
                </button>
            </div>
        </form>
    </div>
    
    <script>
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            event.currentTarget.classList.add('active');
        }
        
        // Set default values if not set
        window.addEventListener('load', function() {
            const settings = <?php echo json_encode($current_settings); ?>;
            
            // Set default values for missing settings
            const defaults = {
                'site_name': 'PARKE Parking System',
                'site_email': 'admin@parke.com',
                'currency': 'USD',
                'timezone': 'UTC',
                'booking_buffer': 15,
                'max_booking_days': 30,
                'tax_rate': 8.5,
                'cancellation_hours': 2,
                'auto_cancel_minutes': 15,
                'reminder_hours': 24,
                'max_login_attempts': 5,
                'session_timeout': 30
            };
            
            // You could auto-populate form fields here if needed
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>