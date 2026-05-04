<?php
// includes/auth.php
// Authentication-specific functions only

require_once 'functions.php';

function register_user($username, $email, $password, $phone = null, $user_type = 'driver') {
    $conn = get_db_connection();
    
    // Validate inputs
    if (!validate_email($email)) {
        return ['success' => false, 'message' => 'Invalid email address'];
    }
    
    if (!validate_password($password)) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters with uppercase, lowercase, and number'];
    }
    
    if ($phone && !validate_phone($phone)) {
        return ['success' => false, 'message' => 'Invalid phone number'];
    }
    
    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Check if user already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
    $stmt->bind_param("ss", $email, $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return ['success' => false, 'message' => 'Username or email already exists'];
    }
    
    // Insert new user
    $stmt = $conn->prepare("INSERT INTO users (username, email, password, phone, user_type) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $username, $email, $hashed_password, $phone, $user_type);
    
    if ($stmt->execute()) {
        $user_id = $stmt->insert_id;
        $_SESSION['user_id'] = $user_id;
        $_SESSION['user_type'] = $user_type;
        $_SESSION['username'] = $username;
        
        // Set success message
        set_message('Registration successful! Welcome to Smart Parking Finder.', 'success');
        
        return ['success' => true, 'message' => 'Registration successful', 'user_id' => $user_id];
    } else {
        return ['success' => false, 'message' => 'Registration failed: ' . $conn->error];
    }
}

function login_user($email, $password) {
    $conn = get_db_connection();
    
    $stmt = $conn->prepare("SELECT id, username, email, password, user_type FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_type'] = $user['user_type'];
            $_SESSION['username'] = $user['username'];
            
            // Set success message
            set_message('Login successful! Welcome back.', 'success');
            
            return ['success' => true, 'message' => 'Login successful'];
        }
    }
    
    return ['success' => false, 'message' => 'Invalid email or password'];
}

function logout_user() {
    // Set logout message before destroying session
    set_message('You have been logged out successfully.', 'info');
    
    session_destroy();
    // Start new session for messages
    session_start();
    redirect('index.php');
}
?>