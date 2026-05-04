<?php
// includes/functions.php
// Database connection and general utility functions only

session_start();

// Database connection
function get_db_connection() {
    static $conn = null;
    
    if ($conn === null) {
        $host = 'localhost';
        $username = 'root'; // Change as needed
        $password = 'root'; // Change as needed
        $database = 'parke'; // Your database name
        
        $conn = new mysqli($host, $username, $password, $database);
        
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }
    }
    
    return $conn;
}

// Check if user is logged in
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// Redirect to login if user is not logged in
function require_login() {
    if (!is_logged_in()) {
        set_message('Please login to access this page.', 'warning');
        redirect('login.php');
    }
}

// Redirect to home if user is already logged in
function redirect_if_logged_in() {
    if (is_logged_in()) {
        redirect('index.php');
    }
}

// Get current logged in user data
function get_logged_in_user() {
    if (isset($_SESSION['user_id'])) {
        $conn = get_db_connection();
        $stmt = $conn->prepare("SELECT id, username, email, user_type, phone, picture, city, created_at FROM users WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    return null;
}

// Check if user is admin
function is_admin() {
    if (isset($_SESSION['user_type'])) {
        return $_SESSION['user_type'] === 'admin';
    }
    return false;
}

// Require admin access
function require_admin() {
    if (!is_admin()) {
        set_message('Access denied. Admin privileges required.', 'error');
        redirect('index.php');
    }
}

// Get any user by ID
function get_user_by_id($user_id) {
    $conn = get_db_connection();
    $stmt = $conn->prepare("SELECT id, username, email, user_type, phone, picture, city, created_at FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// General utility functions
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Update user profile
function update_user_profile($user_id, $username, $email, $phone, $city = null) {
    $conn = get_db_connection();
    
    // Check if username or email already exists for another user
    $check_stmt = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
    $check_stmt->bind_param("ssi", $username, $email, $user_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows > 0) {
        return ['success' => false, 'message' => 'Username or email already exists.'];
    }
    
    // Update user profile
    $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, phone = ?, city = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $username, $email, $phone, $city, $user_id);
    
    if ($stmt->execute()) {
        $_SESSION['username'] = $username;
        set_message('Profile updated successfully.', 'success');
        return ['success' => true, 'message' => 'Profile updated successfully.'];
    } else {
        return ['success' => false, 'message' => 'Failed to update profile.'];
    }
}

// Upload profile picture
function upload_profile_picture($user_id, $file) {
    $conn = get_db_connection();
    
    // Define upload directory - adjust path based on your structure
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/work/Parke/uploads/profiles/';
    
    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Validate file
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
    $max_size = 2 * 1024 * 1024; // 2MB
    
    if (!in_array($file['type'], $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type. Please upload JPG, PNG, or GIF.'];
    }
    
    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'File too large. Maximum size is 2MB.'];
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'profile_' . $user_id . '_' . time() . '.' . $extension;
    $filepath = $upload_dir . $filename;
    
    // Get old profile picture
    $stmt = $conn->prepare("SELECT picture FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $old_picture = $user['picture'] ?? null;
    
    // Upload file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        // Update database
        $update_stmt = $conn->prepare("UPDATE users SET picture = ? WHERE id = ?");
        $update_stmt->bind_param("si", $filename, $user_id);
        
        if ($update_stmt->execute()) {
            // Delete old profile picture if exists
            if ($old_picture && file_exists($upload_dir . $old_picture)) {
                unlink($upload_dir . $old_picture);
            }
            set_message('Profile picture uploaded successfully.', 'success');
            return ['success' => true, 'message' => 'Profile picture uploaded successfully.'];
        } else {
            // Delete uploaded file if database update fails
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            return ['success' => false, 'message' => 'Failed to update database.'];
        }
    } else {
        return ['success' => false, 'message' => 'Failed to upload file.'];
    }
}

// Remove profile picture
function remove_profile_picture($user_id) {
    $conn = get_db_connection();
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/work/Parke/uploads/profiles/';
    
    // Get current profile picture
    $stmt = $conn->prepare("SELECT picture FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $old_picture = $user['picture'] ?? null;
    
    if ($old_picture) {
        // Delete file
        if (file_exists($upload_dir . $old_picture)) {
            unlink($upload_dir . $old_picture);
        }
        
        // Update database
        $update_stmt = $conn->prepare("UPDATE users SET picture = NULL WHERE id = ?");
        $update_stmt->bind_param("i", $user_id);
        
        if ($update_stmt->execute()) {
            set_message('Profile picture removed successfully.', 'success');
            return ['success' => true, 'message' => 'Profile picture removed successfully.'];
        }
    }
    
    return ['success' => false, 'message' => 'No profile picture to remove.'];
}

// Change password
function change_password($user_id, $current_password, $new_password) {
    $conn = get_db_connection();
    
    // Get current password hash
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    // Verify current password
    if (!password_verify($current_password, $user['password'])) {
        return ['success' => false, 'message' => 'Current password is incorrect.'];
    }
    
    // Validate new password
    if (strlen($new_password) < 8) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters long.'];
    }
    
    if (!preg_match('/[A-Z]/', $new_password) || !preg_match('/[a-z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
        return ['success' => false, 'message' => 'Password must contain uppercase, lowercase, and number.'];
    }
    
    // Update password
    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $update_stmt->bind_param("si", $new_password_hash, $user_id);
    
    if ($update_stmt->execute()) {
        set_message('Password changed successfully.', 'success');
        return ['success' => true, 'message' => 'Password changed successfully.'];
    } else {
        return ['success' => false, 'message' => 'Failed to change password.'];
    }
}

// Parking-related functions
function get_parking_locations($city = null, $limit = null) {
    $conn = get_db_connection();
    
    $sql = "SELECT * FROM parking_locations WHERE status = 'active'";
    $params = [];
    $types = "";
    
    if ($city) {
        $sql .= " AND city = ?";
        $params[] = $city;
        $types .= "s";
    }
    
    $sql .= " ORDER BY name";
    
    if ($limit) {
        $sql .= " LIMIT ?";
        $params[] = $limit;
        $types .= "i";
    }
    
    $stmt = $conn->prepare($sql);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

function get_parking_location_by_id($location_id) {
    $conn = get_db_connection();
    $stmt = $conn->prepare("SELECT * FROM parking_locations WHERE id = ?");
    $stmt->bind_param("i", $location_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function get_available_slots($location_id) {
    $conn = get_db_connection();
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM parking_slots WHERE location_id = ? AND status = 'available'");
    $stmt->bind_param("i", $location_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['count'];
}

function get_slots_by_location($location_id) {
    $conn = get_db_connection();
    $stmt = $conn->prepare("SELECT * FROM parking_slots WHERE location_id = ? ORDER BY slot_number");
    $stmt->bind_param("i", $location_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Input validation
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validate_phone($phone) {
    return preg_match('/^[0-9]{10,15}$/', $phone);
}

function validate_password($password) {
    // At least 8 characters, 1 uppercase, 1 lowercase, 1 number
    return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[a-zA-Z\d]{8,}$/', $password);
}

// Display messages
function display_message() {
    if (isset($_SESSION['message'])) {
        $message = $_SESSION['message'];
        unset($_SESSION['message']);
        
        $type = isset($_SESSION['message_type']) ? $_SESSION['message_type'] : 'info';
        unset($_SESSION['message_type']);
        
        $colors = [
            'success' => 'alert-success',
            'error' => 'alert-danger',
            'warning' => 'alert-warning',
            'info' => 'alert-info'
        ];
        
        $color_class = $colors[$type] ?? $colors['info'];
        
        return "<div class='alert $color_class alert-dismissible fade show' role='alert'>
                $message
                <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
            </div>";
    }
    return '';
}

function set_message($message, $type = 'info') {
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
}

// Redirect function
function redirect($url) {
    header("Location: $url");
    exit();
}

// Format date/time
function format_datetime($datetime, $format = 'Y-m-d H:i:s') {
    if (empty($datetime)) return '';
    $date = new DateTime($datetime);
    return $date->format($format);
}

// Format currency
function format_currency($amount) {
    return '$' . number_format($amount, 2);
}
?>