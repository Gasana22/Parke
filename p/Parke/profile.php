<?php
// profile.php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

require_login();
$user = get_logged_in_user();

// Get user's current location (for sidebar)
$user_city = $_SESSION['user_city'] ?? '';

// Get active bookings count for sidebar
$conn = get_db_connection();
$active_stmt = $conn->prepare("
    SELECT COUNT(*) as active_count 
    FROM bookings 
    WHERE user_id = ? AND end_time IS NULL
");
$active_stmt->bind_param("i", $user['id']);
$active_stmt->execute();
$active_count = $active_stmt->get_result()->fetch_assoc()['active_count'] ?? 0;

$error = '';
$success = '';

// Handle profile picture upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_picture'])) {
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $upload_result = upload_profile_picture($user['id'], $_FILES['profile_picture']);
        if ($upload_result['success']) {
            $success = $upload_result['message'];
            $user = get_logged_in_user(); // Refresh user data
        } else {
            $error = $upload_result['message'];
        }
    } else {
        $error = 'Please select a valid image file.';
    }
}

// Handle profile picture removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_picture'])) {
    $remove_result = remove_profile_picture($user['id']);
    if ($remove_result['success']) {
        $success = $remove_result['message'];
        $user = get_logged_in_user(); // Refresh user data
    } else {
        $error = $remove_result['message'];
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $username = sanitize_input($_POST['username']);
    $email = sanitize_input($_POST['email']);
    $phone = sanitize_input($_POST['phone']);
    $city = sanitize_input($_POST['city']);
    
    $result = update_user_profile($user['id'], $username, $email, $phone, $city);
    
    if ($result['success']) {
        $success = $result['message'];
        $user = get_logged_in_user(); // Refresh user data
        $_SESSION['user_city'] = $city; // Update session city
        $user_city = $city;
    } else {
        $error = $result['message'];
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if ($new_password !== $confirm_password) {
        $error = 'New passwords do not match.';
    } else {
        $result = change_password($user['id'], $current_password, $new_password);
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
}

// Function to get profile picture path
function get_profile_picture_path($user) {
    if (!empty($user['picture']) && file_exists('uploads/profiles/' . $user['picture'])) {
        return 'uploads/profiles/' . $user['picture'];
    }
    return null;
}

$profile_picture = get_profile_picture_path($user);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Parke</title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-light: #3b82f6;
            --primary-dark: #1d4ed8;
            --primary-soft: #dbeafe;
            --secondary: #0ea5e9;
            --secondary-light: #38bdf8;
            --accent: #8b5cf6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1e293b;
            --dark-light: #334155;
            --light: #f8fafc;
            --gray: #64748b;
            --gray-light: #e2e8f0;
            --card-bg: #ffffff;
            
            --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            --gradient-primary-reverse: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%);
            --gradient-light: linear-gradient(135deg, #f0f9ff 0%, #e0f7ff 100%);
            --gradient-card: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            --gradient-success: linear-gradient(135deg, var(--success) 0%, #34d399 100%);
            --gradient-danger: linear-gradient(135deg, var(--danger) 0%, #f87171 100%);
            --gradient-stat: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            --gradient-avatar: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            
            --shadow-sm: 0 1px 3px rgba(30, 41, 59, 0.1);
            --shadow: 0 4px 6px -1px rgba(30, 41, 59, 0.1), 0 2px 4px -1px rgba(30, 41, 59, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(30, 41, 59, 0.1), 0 4px 6px -2px rgba(30, 41, 59, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(30, 41, 59, 0.1), 0 10px 10px -5px rgba(30, 41, 59, 0.04);
            --shadow-primary: 0 10px 15px -3px rgba(37, 99, 235, 0.2);
            --shadow-success: 0 10px 15px -3px rgba(16, 185, 129, 0.2);
            --shadow-danger: 0 10px 15px -3px rgba(239, 68, 68, 0.2);
            
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 16px;
            --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
        }
        
        body {
            background: var(--gradient-light);
            color: var(--dark);
            min-height: 100vh;
            line-height: 1.5;
            font-weight: 400;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .top-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            margin-bottom: 20px;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-dark);
            text-decoration: none;
            transition: var(--transition);
        }
        
        .logo:hover {
            color: var(--primary);
        }
        
        .logo-icon {
            width: 42px;
            height: 42px;
            background: var(--gradient-primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            box-shadow: var(--shadow-primary);
        }
        
        .user-menu {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .user-avatar {
            width: 48px;
            height: 48px;
            background: var(--gradient-avatar);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
            box-shadow: var(--shadow);
            transition: var(--transition);
            object-fit: cover;
        }
        
        .user-avatar-img {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid white;
            box-shadow: var(--shadow);
        }
        
        .user-avatar:hover {
            transform: scale(1.05);
            box-shadow: var(--shadow-lg);
        }
        
        .user-details h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
        }
        
        .user-details p {
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        .dashboard-layout {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }
        
        .sidebar {
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            padding: 25px;
            box-shadow: var(--shadow-lg);
            position: sticky;
            top: 20px;
            height: fit-content;
            border: 1px solid var(--gray-light);
        }
        
        .sidebar-nav {
            list-style: none;
            margin-bottom: 30px;
        }
        
        .sidebar-nav li {
            margin-bottom: 8px;
        }
        
        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            color: var(--dark-light);
            text-decoration: none;
            border-radius: var(--radius);
            transition: var(--transition);
            font-weight: 500;
        }
        
        .sidebar-nav a:hover {
            background: var(--primary-soft);
            color: var(--primary);
            transform: translateX(4px);
        }
        
        .sidebar-nav a.active {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--shadow-primary);
            transform: translateX(4px);
        }
        
        .sidebar-nav a.active i {
            color: white;
        }
        
        .sidebar-nav i {
            width: 20px;
            text-align: center;
            color: var(--gray);
            transition: var(--transition);
        }
        
        .location-card {
            background: var(--gradient-light);
            border-radius: var(--radius);
            padding: 20px;
            margin-top: 20px;
            border: 1px solid var(--gray-light);
            transition: var(--transition);
        }
        
        .location-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        .location-card h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 12px;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .location-display {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .location-display i {
            color: var(--primary);
            font-size: 1.2rem;
        }
        
        .location-display span {
            font-weight: 600;
            color: var(--dark);
        }
        
        .main-content {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }
        
        .profile-header {
            text-align: center;
            margin-bottom: 30px;
            padding: 30px;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-light);
            position: relative;
            overflow: hidden;
        }
        
        .profile-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
        }
        
        .profile-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 10px;
        }
        
        .profile-header p {
            color: var(--gray);
            font-size: 1.1rem;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .card {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 30px;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-light);
            background: var(--gradient-card);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
            opacity: 0;
            transition: var(--transition);
        }
        
        .card:hover::before {
            opacity: 1;
        }
        
        .danger-zone.card::before {
            background: var(--gradient-danger);
        }
        
        .card-header {
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .card-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-title i {
            color: var(--primary);
        }
        
        /* Profile Picture Styles */
        .profile-picture-section {
            display: flex;
            align-items: center;
            gap: 30px;
            margin-bottom: 30px;
            padding: 20px;
            background: var(--light);
            border-radius: var(--radius);
        }
        
        .profile-picture-container {
            position: relative;
            width: 120px;
            height: 120px;
        }
        
        .profile-picture {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: var(--shadow-lg);
        }
        
        .profile-picture-initials {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: var(--gradient-avatar);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            font-weight: 600;
            border: 4px solid white;
            box-shadow: var(--shadow-lg);
        }
        
        .profile-picture-upload {
            flex: 1;
        }
        
        .upload-btn-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
        }
        
        .upload-btn-wrapper input[type=file] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }
        
        .picture-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            border: none;
        }
        
        .btn-primary {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--shadow-sm);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-primary);
        }
        
        .btn-danger {
            background: var(--gradient-danger);
            color: white;
            box-shadow: var(--shadow-sm);
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-danger);
        }
        
        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }
        
        .btn-outline:hover {
            background: var(--primary-soft);
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: var(--gray-light);
            color: var(--dark);
        }
        
        .btn-secondary:hover {
            background: var(--gray);
            color: white;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.95rem;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--gray-light);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
            background: white;
            color: var(--dark);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        .form-control:disabled {
            background: var(--light);
            color: var(--gray);
            cursor: not-allowed;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            border: 1px solid transparent;
            font-weight: 500;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-color: rgba(16, 185, 129, 0.2);
            border-left: 4px solid var(--success);
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border-color: rgba(239, 68, 68, 0.2);
            border-left: 4px solid var(--danger);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .stat-card {
            background: var(--gradient-stat);
            padding: 25px;
            border-radius: var(--radius);
            text-align: center;
            border: 1px solid var(--gray-light);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
            border-color: var(--primary-light);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
            opacity: 0;
            transition: var(--transition);
        }
        
        .stat-card:hover::before {
            opacity: 1;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 5px;
            text-shadow: 0 2px 4px rgba(37, 99, 235, 0.1);
        }
        
        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .danger-zone {
            border: 2px solid var(--danger);
            background: linear-gradient(135deg, #ffffff 0%, #fef2f2 100%);
        }
        
        .danger-zone .card-title {
            color: var(--danger);
        }
        
        .danger-zone .card-title i {
            color: var(--danger);
        }
        
        .info-text {
            color: var(--gray);
            font-size: 0.9rem;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .info-text i {
            color: var(--primary);
        }
        
        .image-preview {
            margin-top: 15px;
            max-width: 200px;
            border-radius: var(--radius);
            overflow: hidden;
            border: 2px solid var(--gray-light);
        }
        
        .image-preview img {
            width: 100%;
            height: auto;
            display: block;
        }
        
        @media (max-width: 1024px) {
            .dashboard-layout {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                position: static;
            }
            
            .profile-picture-section {
                flex-direction: column;
                text-align: center;
            }
            
            .picture-actions {
                justify-content: center;
            }
        }
        
        @media (max-width: 768px) {
            .top-nav {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .user-menu {
                width: 100%;
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
        <!-- Top Navigation -->
        <div class="top-nav">
            <a href="index.php" class="logo">
                <div class="logo-icon">
                    <i class="fas fa-parking"></i>
                </div>
                <span>Parke</span>
            </a>
            
            <div class="user-menu">
                <div class="user-info">
                    <?php if ($profile_picture): ?>
                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile" class="user-avatar-img">
                    <?php else: ?>
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                    <div class="user-details">
                        <h3><?php echo htmlspecialchars($user['username']); ?></h3>
                        <p><?php echo htmlspecialchars($user['email']); ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main Dashboard Layout -->
        <div class="dashboard-layout">
            <!-- Sidebar -->
            <div class="sidebar">
                <ul class="sidebar-nav">
                    <li>
                        <a href="dashboard.php">
                            <i class="fas fa-home"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="find_parking.php">
                            <i class="fas fa-search"></i>
                            <span>Find Parking</span>
                        </a>
                    </li>
                    <li>
                        <a href="booking.php">
                            <i class="fas fa-calendar-alt"></i>
                            <span>My Bookings</span>
                            <?php if ($active_count > 0): ?>
                                <span style="margin-left: auto; background: var(--primary); color: white; width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.7rem;">
                                    <?php echo $active_count; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="profile.php" class="active">
                            <i class="fas fa-user"></i>
                            <span>Profile</span>
                        </a>
                    </li>
                    <li>
                        <a href="favorites.php">
                            <i class="fas fa-star"></i>
                            <span>Favorites</span>
                        </a>
                    </li>
                    <li>
                        <a href="history.php">
                            <i class="fas fa-history"></i>
                            <span>History</span>
                        </a>
                    </li>
                    <li>
                        <a href="logout.php">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </li>
                </ul>
                
                <div class="location-card">
                    <h3><i class="fas fa-map-marker-alt"></i> Your Location</h3>
                    <div class="location-display">
                        <i class="fas fa-location-dot"></i>
                        <span><?php echo $user_city ? htmlspecialchars($user_city) : 'Not set'; ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="main-content">
                <div class="profile-header">
                    <h1>My Profile</h1>
                    <p>Manage your account settings and preferences</p>
                </div>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Profile Picture Card -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-camera"></i> Profile Picture
                        </h2>
                    </div>
                    
                    <div class="profile-picture-section">
                        <div class="profile-picture-container">
                            <?php if ($profile_picture): ?>
    <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile" class="user-avatar-img">
<?php else: ?>
    <div class="user-avatar">
        <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
    </div>
<?php endif; ?>
                        </div>
                        
                        <div class="profile-picture-upload">
                            <form method="POST" enctype="multipart/form-data" id="pictureUploadForm">
                                <p style="color: var(--gray); margin-bottom: 15px;">
                                    Upload a profile picture to make your account more personal. 
                                    Accepted formats: JPG, PNG, GIF (max 2MB)
                                </p>
                                
                                <div class="picture-actions">
                                    <div class="upload-btn-wrapper">
                                        <button type="button" class="btn btn-outline" onclick="document.getElementById('fileInput').click();">
                                            <i class="fas fa-upload"></i> Choose Image
                                        </button>
                                        <input type="file" id="fileInput" name="profile_picture" accept="image/jpeg,image/png,image/gif" style="display: none;">
                                    </div>
                                    
                                    <button type="submit" name="upload_picture" class="btn btn-primary" id="uploadBtn" disabled>
                                        <i class="fas fa-cloud-upload-alt"></i> Upload
                                    </button>
                                    
                                    <?php if ($profile_picture): ?>
                                        <button type="submit" name="remove_picture" class="btn btn-danger" onclick="return confirm('Are you sure you want to remove your profile picture?');">
                                            <i class="fas fa-trash-alt"></i> Remove
                                        </button>
                                    <?php endif; ?>
                                </div>
                                
                                <div id="imagePreview" class="image-preview" style="display: none;">
                                    <img src="" alt="Preview">
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Profile Information Card -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-user-circle"></i> Profile Information
                        </h2>
                    </div>
                    
                    <form method="POST" action="">
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" class="form-control" 
                                   value="<?php echo htmlspecialchars($user['username']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" class="form-control" 
                                   value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone" class="form-control" 
                                   value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="city">City</label>
                            <input type="text" id="city" name="city" class="form-control" 
                                   value="<?php echo htmlspecialchars($user['city'] ?? ''); ?>" 
                                   placeholder="Enter your city">
                            <div class="info-text">
                                <i class="fas fa-map-pin"></i> Your city helps us show nearby parking spots
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="user_type">Account Type</label>
                            <input type="text" id="user_type" class="form-control" 
                                   value="<?php echo ucfirst($user['user_type']); ?>" disabled>
                            <div class="info-text">
                                <i class="fas fa-info-circle"></i> Account type cannot be changed
                            </div>
                        </div>
                        
                        <button type="submit" name="update_profile" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Profile
                        </button>
                    </form>
                </div>
                
                <!-- Change Password Card -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-lock"></i> Change Password
                        </h2>
                    </div>
                    
                    <form method="POST" action="">
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" class="form-control" required>
                            <div class="info-text">
                                <i class="fas fa-shield-alt"></i> Minimum 8 characters with uppercase, lowercase, and number
                            </div>
                            <div id="password-strength"></div>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                        </div>
                        
                        <button type="submit" name="change_password" class="btn btn-primary">
                            <i class="fas fa-key"></i> Change Password
                        </button>
                    </form>
                </div>
                
                <!-- Account Statistics -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-chart-bar"></i> Account Statistics
                        </h2>
                    </div>
                    
                    <?php
                    // Get user statistics
                    $conn = get_db_connection();
                    
                    // Total bookings
                    $total_stmt = $conn->prepare("SELECT COUNT(*) as total FROM bookings WHERE user_id = ?");
                    $total_stmt->bind_param("i", $user['id']);
                    $total_stmt->execute();
                    $total_result = $total_stmt->get_result()->fetch_assoc();
                    
                    // Active bookings
                    $active_stmt = $conn->prepare("SELECT COUNT(*) as active FROM bookings WHERE user_id = ? AND end_time IS NULL");
                    $active_stmt->bind_param("i", $user['id']);
                    $active_stmt->execute();
                    $active_result = $active_stmt->get_result()->fetch_assoc();
                    
                    // Total spent
                    $spent_stmt = $conn->prepare("SELECT SUM(total_cost) as spent FROM bookings WHERE user_id = ? AND payment_status = 'paid'");
                    $spent_stmt->bind_param("i", $user['id']);
                    $spent_stmt->execute();
                    $spent_result = $spent_stmt->get_result()->fetch_assoc();
                    
                    // Member since
                    $member_since = date('F Y', strtotime($user['created_at']));
                    ?>
                    
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $total_result['total']; ?></div>
                            <div class="stat-label">Total Bookings</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $active_result['active']; ?></div>
                            <div class="stat-label">Active Bookings</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-number">UGX<?php echo number_format($spent_result['spent'] ?? 0, 2); ?></div>
                            <div class="stat-label">Total Spent</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $member_since; ?></div>
                            <div class="stat-label">Member Since</div>
                        </div>
                    </div>
                </div>
                
                <!-- Danger Zone -->
                <div class="card danger-zone">
                    <div class="card-header">
                        <h2 class="card-title" style="color: var(--danger);">
                            <i class="fas fa-exclamation-triangle"></i> Danger Zone
                        </h2>
                    </div>
                    
                    <p style="color: var(--gray); margin-bottom: 20px;">
                        Once you delete your account, there is no going back. Please be certain.
                    </p>
                    
                    <button type="button" class="btn btn-danger" onclick="confirmDeleteAccount()">
                        <i class="fas fa-trash-alt"></i> Delete My Account
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // File input change handler
        document.getElementById('fileInput').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const uploadBtn = document.getElementById('uploadBtn');
            const preview = document.getElementById('imagePreview');
            const previewImg = preview.querySelector('img');
            
            if (file) {
                // Check file size (2MB limit)
                if (file.size > 2 * 1024 * 1024) {
                    alert('File is too large. Maximum size is 2MB.');
                    this.value = '';
                    uploadBtn.disabled = true;
                    preview.style.display = 'none';
                    return;
                }
                
                // Check file type
                if (!file.type.match('image.*')) {
                    alert('Please select an image file (JPG, PNG, or GIF).');
                    this.value = '';
                    uploadBtn.disabled = true;
                    preview.style.display = 'none';
                    return;
                }
                
                // Enable upload button
                uploadBtn.disabled = false;
                
                // Show preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            } else {
                uploadBtn.disabled = true;
                preview.style.display = 'none';
            }
        });
        
        // Password strength indicator
        const passwordInput = document.getElementById('new_password');
        if (passwordInput) {
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                let strength = { label: 'None', color: 'var(--gray)' };
                
                if (password.length > 0) {
                    if (password.length < 8) {
                        strength = { label: 'Weak', color: 'var(--danger)' };
                    } else {
                        const hasUpper = /[A-Z]/.test(password);
                        const hasLower = /[a-z]/.test(password);
                        const hasNumber = /\d/.test(password);
                        
                        let score = 0;
                        if (hasUpper) score++;
                        if (hasLower) score++;
                        if (hasNumber) score++;
                        if (password.length >= 12) score++;
                        
                        if (score >= 4) strength = { label: 'Strong', color: 'var(--success)' };
                        else if (score >= 3) strength = { label: 'Good', color: '#f59e0b' };
                        else strength = { label: 'Fair', color: '#f59e0b' };
                    }
                }
                
                let indicator = document.getElementById('password-strength');
                if (!indicator) {
                    indicator = document.createElement('div');
                    indicator.id = 'password-strength';
                    passwordInput.parentNode.appendChild(indicator);
                }
                
                indicator.textContent = strength.label !== 'None' ? `Strength: ${strength.label}` : '';
                indicator.style.color = strength.color;
                indicator.style.marginTop = '5px';
                indicator.style.fontSize = '0.85rem';
                indicator.style.fontWeight = '500';
            });
        }

        // Live password match indicator
        const confirmPwd = document.getElementById('confirm_password');
        const newPwd = document.getElementById('new_password');
        if (confirmPwd && newPwd) {
            function checkMatch() {
                if (confirmPwd.value) {
                    if (newPwd.value !== confirmPwd.value) {
                        confirmPwd.style.borderColor = 'var(--danger)';
                        confirmPwd.style.backgroundColor = 'rgba(239, 68, 68, 0.05)';
                    } else {
                        confirmPwd.style.borderColor = 'var(--success)';
                        confirmPwd.style.backgroundColor = 'rgba(16, 185, 129, 0.05)';
                    }
                } else {
                    confirmPwd.style.borderColor = 'var(--gray-light)';
                    confirmPwd.style.backgroundColor = 'white';
                }
            }
            newPwd.addEventListener('input', checkMatch);
            confirmPwd.addEventListener('input', checkMatch);
        }
        
        function confirmDeleteAccount() {
            if (confirm('Are you sure you want to delete your account? This action cannot be undone.')) {
                window.location.href = 'delete_account.php';
            }
        }
    </script>
</body>
</html>