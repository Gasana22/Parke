<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$conn = getConnection();

// Function to generate random password
function generateLocationPassword($length = 8) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

// Function to generate unique username
function generateLocationUsername($location_name, $conn) {
    $base = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $location_name));
    $base = substr($base, 0, 15); // Limit length
    
    $username = $base;
    $counter = 1;
    
    // Check if username exists
    $check = $conn->query("SELECT id FROM parking_locations WHERE location_username = '$username'");
    while ($check->num_rows > 0) {
        $username = $base . $counter;
        $check = $conn->query("SELECT id FROM parking_locations WHERE location_username = '$username'");
        $counter++;
    }
    
    return $username;
}

$message = '';
$message_type = '';
$generated_credentials = null;

// Add new location
if (isset($_POST['add_location'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $address = $conn->real_escape_string($_POST['address']);
    $city = $conn->real_escape_string($_POST['city']);
    $latitude = (float)$_POST['latitude'];
    $longitude = (float)$_POST['longitude'];
    $total_slots = (int)$_POST['total_slots'];
    $price_per_hour = (float)$_POST['price_per_hour'];
    $admin_id = $_SESSION['admin_id'];
    $status = $conn->real_escape_string($_POST['status']);
    
    // Location manager details
    $location_manager_name = isset($_POST['location_manager_name']) ? $conn->real_escape_string($_POST['location_manager_name']) : '';
    $location_manager_phone = isset($_POST['location_manager_phone']) ? $conn->real_escape_string($_POST['location_manager_phone']) : '';
    $location_manager_email = isset($_POST['location_manager_email']) ? $conn->real_escape_string($_POST['location_manager_email']) : '';
    
    // Access permissions
    $has_camera_access = isset($_POST['has_camera_access']) ? 1 : 0;
    $has_gate_access = isset($_POST['has_gate_access']) ? 1 : 0;
    
    // Generate credentials
    $generate_credentials = isset($_POST['generate_credentials']) ? true : false;
    $location_username = '';
    $location_password = '';
    
    if ($generate_credentials) {
        $location_username = generateLocationUsername($name, $conn);
        $plain_password = generateLocationPassword();
        $location_password = password_hash($plain_password, PASSWORD_DEFAULT);
        
        // Store plain password temporarily for display
        $_SESSION['temp_location_password'] = $plain_password;
        $_SESSION['temp_location_username'] = $location_username;
    } else if (!empty($_POST['custom_username']) && !empty($_POST['custom_password'])) {
        // Use custom credentials
        $location_username = $conn->real_escape_string($_POST['custom_username']);
        $plain_password = $_POST['custom_password'];
        $location_password = password_hash($plain_password, PASSWORD_DEFAULT);
        
        // Check if username is unique
        $check = $conn->query("SELECT id FROM parking_locations WHERE location_username = '$location_username'");
        if ($check->num_rows > 0) {
            $message = "Username already exists! Please choose another.";
            $message_type = "error";
        }
    }
    
    // Handle additional fields
    $state = isset($_POST['state']) ? $conn->real_escape_string($_POST['state']) : '';
    $security_info = isset($_POST['security_info']) ? $conn->real_escape_string($_POST['security_info']) : '';
    $amenities = isset($_POST['amenities']) ? $conn->real_escape_string($_POST['amenities']) : '';
    $operating_hours = isset($_POST['operating_hours']) ? $conn->real_escape_string($_POST['operating_hours']) : '24/7';
    $contact_phone = isset($_POST['contact_phone']) ? $conn->real_escape_string($_POST['contact_phone']) : '';
    $contact_email = isset($_POST['contact_email']) ? $conn->real_escape_string($_POST['contact_email']) : '';
    
    // Check if we need to add new columns
    $check_columns = $conn->query("SHOW COLUMNS FROM parking_locations LIKE 'state'");
    if ($check_columns->num_rows == 0) {
        // Add missing columns including credential fields
        $conn->query("ALTER TABLE parking_locations 
            ADD COLUMN state VARCHAR(100),
            ADD COLUMN security_info TEXT,
            ADD COLUMN amenities TEXT,
            ADD COLUMN operating_hours VARCHAR(100),
            ADD COLUMN contact_phone VARCHAR(20),
            ADD COLUMN contact_email VARCHAR(100),
            ADD COLUMN image_path VARCHAR(255),
            ADD COLUMN location_username VARCHAR(50) UNIQUE,
            ADD COLUMN location_password VARCHAR(255),
            ADD COLUMN location_manager_name VARCHAR(100),
            ADD COLUMN location_manager_phone VARCHAR(20),
            ADD COLUMN location_manager_email VARCHAR(100),
            ADD COLUMN has_camera_access BOOLEAN DEFAULT FALSE,
            ADD COLUMN has_gate_access BOOLEAN DEFAULT FALSE");
    }
    
    // Handle image upload
    $image_path = '';
    if (isset($_FILES['location_image']) && $_FILES['location_image']['error'] == 0) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
        $max_size = 5 * 1024 * 1024;
        
        if (in_array($_FILES['location_image']['type'], $allowed_types) && 
            $_FILES['location_image']['size'] <= $max_size) {
            
            $ext = pathinfo($_FILES['location_image']['name'], PATHINFO_EXTENSION);
            $filename = 'location_' . time() . '_' . uniqid() . '.' . $ext;
            $upload_dir = '../uploads/locations/';
            
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $upload_path = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['location_image']['tmp_name'], $upload_path)) {
                $image_path = 'uploads/locations/' . $filename;
            }
        }
    }
    
    if (empty($message)) {
        // Insert location with all fields
        $sql = "INSERT INTO parking_locations (
            name, address, city, latitude, longitude, total_slots, 
            price_per_hour, admin_id, status, available_slots, state,
            security_info, amenities, operating_hours, contact_phone, 
            contact_email, image_path, location_username, location_password,
            location_manager_name, location_manager_phone, location_manager_email,
            has_camera_access, has_gate_access
        ) VALUES (
            '$name', '$address', '$city', $latitude, $longitude, 
            $total_slots, $price_per_hour, $admin_id, '$status', $total_slots,
            '$state', '$security_info', '$amenities', '$operating_hours',
            '$contact_phone', '$contact_email', '$image_path',
            '$location_username', '$location_password', '$location_manager_name',
            '$location_manager_phone', '$location_manager_email',
            $has_camera_access, $has_gate_access
        )";
        
        if ($conn->query($sql)) {
            $location_id = $conn->insert_id;
            
            // Create parking slots
            for ($i = 1; $i <= $total_slots; $i++) {
                $slot_number = 'A' . str_pad($i, 3, '0', STR_PAD_LEFT);
                $slot_type = 'standard';
                
                $conn->query("INSERT INTO parking_slots (location_id, slot_number, slot_type, status) 
                             VALUES ($location_id, '$slot_number', '$slot_type', 'available')");
            }
            
            $message = "Location added successfully with $total_slots slots!";
            $message_type = "success";
            
            // Set flag to show credentials if generated
            if ($generate_credentials || !empty($_POST['custom_username'])) {
                $_SESSION['show_location_credentials'] = true;
                $_SESSION['credential_location_name'] = $name;
                $_SESSION['credential_location_username'] = $location_username;
                $_SESSION['credential_location_password'] = $plain_password;
            }
        } else {
            $message = "Error adding location: " . $conn->error;
            $message_type = "error";
        }
    }
}

// Check if we should show credentials
$show_credentials = false;
if (isset($_SESSION['show_location_credentials'])) {
    $show_credentials = true;
    $cred_location_name = $_SESSION['credential_location_name'];
    $cred_username = $_SESSION['credential_location_username'];
    $cred_password = $_SESSION['credential_location_password'];
    
    // Clear from session
    unset($_SESSION['show_location_credentials']);
    unset($_SESSION['credential_location_name']);
    unset($_SESSION['credential_location_username']);
    unset($_SESSION['credential_location_password']);
}

// Delete location
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    
    // Delete associated slots and bookings
    $conn->query("DELETE FROM parking_slots WHERE location_id = $id");
    $conn->query("DELETE FROM bookings WHERE location_id = $id");
    $conn->query("DELETE FROM favorites WHERE location_id = $id");
    
    // Delete location
    if ($conn->query("DELETE FROM parking_locations WHERE id = $id")) {
        $message = "Location deleted successfully!";
        $message_type = "success";
    } else {
        $message = "Error deleting location: " . $conn->error;
        $message_type = "error";
    }
}

// Update location status
if (isset($_POST['update_status'])) {
    $id = (int)$_POST['location_id'];
    $status = $conn->real_escape_string($_POST['status']);
    
    if ($conn->query("UPDATE parking_locations SET status = '$status' WHERE id = $id")) {
        $message = "Location status updated!";
        $message_type = "success";
    }
}

// Get all locations
$locations = $conn->query("
    SELECT pl.*, u.username as admin_name,
           (SELECT COUNT(*) FROM parking_slots WHERE location_id = pl.id) as total_slots_count
    FROM parking_locations pl 
    LEFT JOIN users u ON pl.admin_id = u.id 
    ORDER BY pl.created_at DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Locations - PARKE Admin</title>
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
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
            transition: all 0.3s;
        }
        
        .logout-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header-actions h2 {
            color: #333;
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
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5a67d8;
            transform: translateY(-2px);
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .locations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        
        .location-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .location-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        
        .location-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            border-radius: 5px;
            margin-bottom: 15px;
            background: #f0f0f0;
        }
        
        .location-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 10px;
        }
        
        .location-name {
            font-size: 18px;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }
        
        .location-address {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .location-stats {
            display: flex;
            gap: 15px;
            margin: 15px 0;
            padding: 15px 0;
            border-top: 1px solid #eee;
            border-bottom: 1px solid #eee;
        }
        
        .stat-item {
            text-align: center;
            flex: 1;
        }
        
        .stat-value {
            font-size: 20px;
            font-weight: bold;
            color: #667eea;
        }
        
        .stat-label {
            font-size: 12px;
            color: #666;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .message {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
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
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
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
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .credential-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            animation: slideIn 0.3s ease;
        }
        
        .credential-card h3 {
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .credential-box {
            background: rgba(255,255,255,0.1);
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .credential-label {
            font-weight: bold;
            margin-right: 10px;
        }
        
        .credential-value {
            font-family: monospace;
            font-size: 18px;
            background: rgba(0,0,0,0.3);
            padding: 5px 10px;
            border-radius: 5px;
        }
        
        .copy-btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 5px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .copy-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .warning-note {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 10px;
            border-radius: 5px;
            margin-top: 15px;
            font-size: 14px;
        }
        
        .location-access-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-right: 5px;
        }
        
        .badge-camera {
            background: #17a2b8;
            color: white;
        }
        
        .badge-gate {
            background: #28a745;
            color: white;
        }
        
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: #667eea;
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }
        
        .access-permissions {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        
        .no-locations {
            grid-column: 1/-1;
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 10px;
        }
        
        .no-locations i {
            font-size: 60px;
            color: #ccc;
            margin-bottom: 20px;
        }
        
        .no-locations h3 {
            color: #666;
            margin-bottom: 10px;
        }
        
        .no-locations p {
            color: #999;
        }
        
        #imagePreview img {
            max-width: 200px;
            max-height: 150px;
            object-fit: cover;
            border-radius: 5px;
            margin-top: 10px;
            border: 2px solid #667eea;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            .locations-grid {
                grid-template-columns: 1fr;
            }
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            .modal-content {
                margin: 10% auto;
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><i class="fas fa-map-marker-alt"></i> Manage Locations</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['admin_name']); ?></span>
            <a href="dashboard.php" class="logout-btn"><i class="fas fa-arrow-left"></i> Dashboard</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
    
    <div class="container">
        <!-- Display generated credentials if available -->
        <?php if ($show_credentials): ?>
        <div class="credential-card">
            <h3><i class="fas fa-key"></i> Location Access Credentials Created</h3>
            <p>Location: <strong><?php echo htmlspecialchars($cred_location_name); ?></strong></p>
            
            <div class="credential-box">
                <span><span class="credential-label">Username:</span> <?php echo htmlspecialchars($cred_username); ?></span>
                <button class="copy-btn" onclick="copyToClipboard('<?php echo $cred_username; ?>')">
                    <i class="fas fa-copy"></i> Copy
                </button>
            </div>
            
            <div class="credential-box">
                <span><span class="credential-label">Password:</span> 
                    <span class="credential-value" id="locationPassword"><?php echo htmlspecialchars($cred_password); ?></span>
                </span>
                <button class="copy-btn" onclick="copyToClipboard('<?php echo $cred_password; ?>')">
                    <i class="fas fa-copy"></i> Copy
                </button>
            </div>
            
            <div class="warning-note">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Important:</strong> These credentials will only be shown once. 
                Please save them securely and share with the location manager.
            </div>
        </div>
        <?php endif; ?>
        
        <div class="header-actions">
            <h2>Parking Locations</h2>
            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="fas fa-plus"></i> Add New Location
            </button>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <!-- Rest of your locations grid display code remains the same -->
        <div class="locations-grid">
            <?php if ($locations && $locations->num_rows > 0): ?>
                <?php while($location = $locations->fetch_assoc()): ?>
                    <div class="location-card">
                        <!-- Your existing location card content -->
                        <?php if (!empty($location['image_path'])): ?>
                            <img src="../<?php echo htmlspecialchars($location['image_path']); ?>" 
                                 alt="<?php echo htmlspecialchars($location['name']); ?>" 
                                 class="location-image">
                        <?php else: ?>
                            <div style="background: #f0f0f0; height: 200px; border-radius: 5px; 
                                        display: flex; align-items: center; justify-content: center; 
                                        color: #999; margin-bottom: 15px;">
                                <i class="fas fa-parking" style="font-size: 50px;"></i>
                            </div>
                        <?php endif; ?>
                        
                        <div class="location-header">
                            <div>
                                <h3 class="location-name"><?php echo htmlspecialchars($location['name']); ?></h3>
                                <div class="status-badge status-<?php echo $location['status']; ?>">
                                    <?php echo ucfirst($location['status']); ?>
                                </div>
                                <?php if (!empty($location['location_username'])): ?>
                                    <div style="margin-top: 5px;">
                                        <small>
                                            <i class="fas fa-user-lock"></i> 
                                            Credentials: <strong>Created</strong>
                                        </small>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-weight: bold; color: #28a745; font-size: 20px;">
                                    <i class="fas fa-money-bill-wave" style="margin-right: 5px;"></i>
                                    UGX <?php echo number_format($location['price_per_hour'], 0); ?>/hr
                                </div>
                                <small style="color: #666;">Price per hour</small>
                            </div>
                        </div>
                        
                        <p class="location-address">
                            <i class="fas fa-map-marker-alt"></i> 
                            <?php echo htmlspecialchars($location['address'] . ', ' . $location['city']); ?>
                        </p>
                        
                        <?php if (!empty($location['location_manager_name'])): ?>
                            <p style="margin: 5px 0; color: #666;">
                                <i class="fas fa-user-tie"></i> 
                                Manager: <?php echo htmlspecialchars($location['location_manager_name']); ?>
                            </p>
                        <?php endif; ?>
                        
                        <!-- Access badges -->
                        <div style="margin: 10px 0;">
                            <?php if ($location['has_camera_access']): ?>
                                <span class="location-access-badge badge-camera">
                                    <i class="fas fa-video"></i> Camera Access
                                </span>
                            <?php endif; ?>
                            <?php if ($location['has_gate_access']): ?>
                                <span class="location-access-badge badge-gate">
                                    <i class="fas fa-gate"></i> Gate Access
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Rest of your existing location card content -->
                        <div class="location-stats">
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $location['total_slots']; ?></div>
                                <div class="stat-label">Total Slots</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $location['available_slots']; ?></div>
                                <div class="stat-label">Available</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">
                                    <?php 
                                    $occupied = $location['total_slots'] - $location['available_slots'];
                                    echo $occupied > 0 ? $occupied : 0;
                                    ?>
                                </div>
                                <div class="stat-label">Occupied</div>
                            </div>
                        </div>
                        
                        <div class="action-buttons">
                            <a href="slots.php?location=<?php echo $location['id']; ?>" class="btn btn-primary">
                                <i class="fas fa-parking"></i> Manage Slots
                            </a>
                            
                            <?php if (empty($location['location_username'])): ?>
                                <button class="btn btn-success" onclick="generateLocationCredentials(<?php echo $location['id']; ?>)">
                                    <i class="fas fa-key"></i> Generate Credentials
                                </button>
                            <?php endif; ?>
                            
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="location_id" value="<?php echo $location['id']; ?>">
                                <select name="status" class="form-control" style="padding: 5px; font-size: 12px; width: auto;" 
                                        onchange="this.form.submit()">
                                    <option value="active" <?php echo $location['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $location['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                                <input type="hidden" name="update_status" value="1">
                            </form>
                            
                            <a href="?delete=<?php echo $location['id']; ?>" class="btn btn-danger" 
                               onclick="return confirm('Are you sure? This will delete all slots and bookings for this location.')">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <!-- Your existing no locations message -->
            <?php endif; ?>
        </div>
    </div>
    
   <!-- Add Location Modal - Complete with all fields -->
<div id="locationModal" class="modal">
    <div class="modal-content">
        <h2 style="margin-bottom: 20px;"><i class="fas fa-plus-circle"></i> Add New Parking Location</h2>
        
        <form id="locationForm" method="POST" enctype="multipart/form-data">
            <!-- Basic Information -->
            <div class="form-row">
                <div class="form-group">
                    <label for="name">Location Name *</label>
                    <input type="text" id="name" name="name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="status">Status *</label>
                    <select id="status" name="status" class="form-control" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label for="address">Address *</label>
                <input type="text" id="address" name="address" class="form-control" required>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="city">City *</label>
                    <input type="text" id="city" name="city" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="state">State/Region</label>
                    <input type="text" id="state" name="state" class="form-control">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="latitude">Latitude *</label>
                    <input type="number" step="0.000001" id="latitude" name="latitude" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="longitude">Longitude *</label>
                    <input type="number" step="0.000001" id="longitude" name="longitude" class="form-control" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="total_slots">Total Slots *</label>
                    <input type="number" id="total_slots" name="total_slots" class="form-control" min="1" required>
                </div>
                <div class="form-group">
                    <label for="price_per_hour">Price per Hour (UGX) *</label>
                    <input type="number" step="100" id="price_per_hour" name="price_per_hour" class="form-control" required>
                    <small style="color: #666;">Enter amount in Uganda Shillings</small>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="contact_phone">Contact Phone</label>
                    <input type="tel" id="contact_phone" name="contact_phone" class="form-control">
                </div>
                <div class="form-group">
                    <label for="contact_email">Contact Email</label>
                    <input type="email" id="contact_email" name="contact_email" class="form-control">
                </div>
            </div>
            
            <div class="form-group">
                <label for="operating_hours">Operating Hours</label>
                <input type="text" id="operating_hours" name="operating_hours" class="form-control" 
                       placeholder="e.g., 24/7 or Mon-Fri 8am-10pm" value="24/7">
            </div>
            
            <!-- Location Manager Section -->
            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0;">
                <h4 style="margin-bottom: 15px; color: #333;">
                    <i class="fas fa-user-tie"></i> Location Manager (Optional)
                </h4>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="location_manager_name">Manager Name</label>
                        <input type="text" id="location_manager_name" name="location_manager_name" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="location_manager_phone">Manager Phone</label>
                        <input type="tel" id="location_manager_phone" name="location_manager_phone" class="form-control">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="location_manager_email">Manager Email</label>
                    <input type="email" id="location_manager_email" name="location_manager_email" class="form-control">
                </div>
            </div>
            
            <!-- Access Credentials Section -->
            <div style="background: #e7f3ff; padding: 15px; border-radius: 8px; margin: 20px 0;">
                <h4 style="margin-bottom: 15px; color: #004085;">
                    <i class="fas fa-lock"></i> Location Access Credentials
                </h4>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" name="generate_credentials" value="1" id="generateCredentials" onchange="toggleCredentialsFields()">
                        <span>Generate automatic credentials for this location</span>
                    </label>
                    <small style="color: #666; display: block; margin-left: 25px;">
                        Creates a unique username and secure password for location access
                    </small>
                </div>
                
                <div id="customCredentials" style="display: none; margin-top: 15px;">
                    <p><strong>Or set custom credentials:</strong></p>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="custom_username">Username</label>
                            <input type="text" id="custom_username" name="custom_username" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="custom_password">Password</label>
                            <input type="text" id="custom_password" name="custom_password" class="form-control">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Access Permissions -->
            <div class="access-permissions">
                <h4 style="margin-bottom: 15px;"><i class="fas fa-shield-alt"></i> Access Permissions</h4>
                
                <div style="display: flex; gap: 30px; flex-wrap: wrap;">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <div class="toggle-switch">
                            <input type="checkbox" name="has_camera_access" value="1" id="cameraAccess">
                            <span class="toggle-slider"></span>
                        </div>
                        <span>Camera Access</span>
                    </label>
                    
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <div class="toggle-switch">
                            <input type="checkbox" name="has_gate_access" value="1" id="gateAccess">
                            <span class="toggle-slider"></span>
                        </div>
                        <span>Gate Access</span>
                    </label>
                </div>
            </div>
            
            <!-- Security and Amenities -->
            <div class="form-group">
                <label for="security_info">Security Information</label>
                <textarea id="security_info" name="security_info" class="form-control" rows="3"
                          placeholder="Security cameras, guard patrol, lighting, etc."></textarea>
            </div>
            
            <div class="form-group">
                <label for="amenities">Amenities</label>
                <textarea id="amenities" name="amenities" class="form-control" rows="3"
                          placeholder="EV charging, covered parking, car wash, etc."></textarea>
            </div>
            
            <!-- Image Upload -->
            <div class="form-group">
                <label for="location_image">Location Image</label>
                <input type="file" id="location_image" name="location_image" class="form-control" accept="image/*">
                <small style="color: #666;">Max size: 5MB. Allowed: JPG, PNG, GIF</small>
                <div id="imagePreview" style="margin-top: 10px;"></div>
            </div>
            
            <!-- Form Buttons -->
            <div class="form-group" style="text-align: right; margin-top: 30px;">
                <button type="button" class="btn" onclick="closeModal()" style="margin-right: 10px; background: #6c757d; color: white;">Cancel</button>
                <button type="submit" name="add_location" class="btn btn-primary">Save Location</button>
            </div>
        </form>
    </div>
</div>
    
   <script>
    const modal = document.getElementById('locationModal');
    const locationForm = document.getElementById('locationForm');
    
    function openAddModal() {
        // Set default coordinates (Kampala as example)
        document.getElementById('latitude').value = '0.3476';
        document.getElementById('longitude').value = '32.5825';
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
    }
    
    function closeModal() {
        modal.style.display = 'none';
        locationForm.reset();
        document.getElementById('imagePreview').innerHTML = '';
        document.getElementById('customCredentials').style.display = 'none';
        document.getElementById('generateCredentials').checked = false;
        document.body.style.overflow = 'auto'; // Restore scrolling
    }
    
    function toggleCredentialsFields() {
        const generateCheckbox = document.getElementById('generateCredentials');
        const customFields = document.getElementById('customCredentials');
        
        if (generateCheckbox.checked) {
            customFields.style.display = 'none';
            document.getElementById('custom_username').value = '';
            document.getElementById('custom_password').value = '';
        } else {
            customFields.style.display = 'block';
        }
    }
    
    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(function() {
            alert('Copied to clipboard!');
        }, function() {
            alert('Failed to copy. Please select and copy manually.');
        });
    }
    
    function generateLocationCredentials(locationId) {
        if (confirm('Generate new credentials for this location? This will overwrite any existing credentials.')) {
            window.location.href = 'generate_location_credentials.php?id=' + locationId;
        }
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        if (event.target == modal) {
            closeModal();
        }
    }
    
    // Image preview
    document.getElementById('location_image').addEventListener('change', function(e) {
        const preview = document.getElementById('imagePreview');
        preview.innerHTML = '';
        
        if (this.files && this.files[0]) {
            const reader = new FileReader();
            
            reader.onload = function(e) {
                const img = document.createElement('img');
                img.src = e.target.result;
                img.style.maxWidth = '200px';
                img.style.maxHeight = '150px';
                img.style.objectFit = 'cover';
                img.style.borderRadius = '5px';
                img.style.marginTop = '10px';
                img.style.border = '2px solid #667eea';
                preview.appendChild(img);
            }
            
            reader.readAsDataURL(this.files[0]);
        }
    });
    
    // Form validation
    document.getElementById('locationForm').addEventListener('submit', function(e) {
        const totalSlots = document.getElementById('total_slots').value;
        if (totalSlots < 1) {
            e.preventDefault();
            alert('Total slots must be at least 1');
            return false;
        }
        
        const price = document.getElementById('price_per_hour').value;
        if (price < 0) {
            e.preventDefault();
            alert('Price per hour cannot be negative');
            return false;
        }
    });
</script>
</body>
</html>
<?php $conn->close(); ?>