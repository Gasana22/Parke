<?php
// favorites.php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

require_login();
$user = get_logged_in_user();

// Get user's current location (for sidebar)
$user_city = $_SESSION['user_city'] ?? '';

// Function to get profile picture path
function get_profile_picture_path($user) {
    if (!empty($user['picture']) && file_exists('uploads/profiles/' . $user['picture'])) {
        return 'uploads/profiles/' . $user['picture'];
    }
    return null;
}

$profile_picture = get_profile_picture_path($user);

// First, create favorites table if it doesn't exist
$conn = get_db_connection();

// Check if favorites table exists, create if not
$table_exists = $conn->query("SHOW TABLES LIKE 'favorites'");
if (!$table_exists || $table_exists->num_rows == 0) {
    $create_table_sql = "CREATE TABLE IF NOT EXISTS favorites (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        location_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (location_id) REFERENCES parking_locations(id) ON DELETE CASCADE,
        UNIQUE KEY unique_favorite (user_id, location_id)
    )";
    
    if ($conn->query($create_table_sql)) {
        set_message('Favorites table created!', 'info');
    } else {
        set_message('Error creating favorites table: ' . $conn->error, 'error');
    }
}

// Handle add/remove favorite
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $location_id = intval($_POST['location_id']);
    $action = $_POST['action'];
    
    if ($action === 'add') {
        $stmt = $conn->prepare("INSERT IGNORE INTO favorites (user_id, location_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $user['id'], $location_id);
        $stmt->execute();
        set_message('Location added to favorites!', 'success');
    } elseif ($action === 'remove') {
        $stmt = $conn->prepare("DELETE FROM favorites WHERE user_id = ? AND location_id = ?");
        $stmt->bind_param("ii", $user['id'], $location_id);
        $stmt->execute();
        set_message('Location removed from favorites.', 'info');
    }
    
    redirect('favorites.php');
}

// Get user's favorite locations
$stmt = $conn->prepare("
    SELECT pl.*, 
           COUNT(ps.id) as total_slots,
           SUM(CASE WHEN ps.status = 'available' THEN 1 ELSE 0 END) as available_slots,
           f.created_at as favorite_date
    FROM favorites f
    JOIN parking_locations pl ON f.location_id = pl.id
    LEFT JOIN parking_slots ps ON pl.id = ps.location_id
    WHERE f.user_id = ?
    AND pl.status = 'active'
    GROUP BY pl.id, pl.name, pl.address, pl.city, pl.latitude, pl.longitude, 
             pl.total_slots, pl.available_slots, pl.price_per_hour, 
             pl.admin_id, pl.status, pl.created_at, f.created_at
    ORDER BY f.created_at DESC
");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$favorites = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get active bookings count for sidebar
$active_stmt = $conn->prepare("
    SELECT COUNT(*) as active_count 
    FROM bookings 
    WHERE user_id = ? AND end_time IS NULL
");
$active_stmt->bind_param("i", $user['id']);
$active_stmt->execute();
$active_count = $active_stmt->get_result()->fetch_assoc()['active_count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Favorites</title>
   <style>
    :root {
        /* Updated Blue Color Palette */
        --primary: #2563eb;           /* Vibrant blue - primary brand color */
        --primary-light: #3b82f6;     /* Lighter blue */
        --primary-dark: #1d4ed8;      /* Darker blue */
        --primary-soft: #dbeafe;      /* Soft blue for backgrounds */
        --secondary: #0ea5e9;         /* Sky blue - for secondary elements */
        --secondary-light: #38bdf8;
        --accent: #8b5cf6;            /* Purple accent kept for contrast */
        --success: #10b981;           /* Success green */
        --warning: #f59e0b;           /* Warning amber */
        --danger: #ef4444;            /* Danger red */
        --dark: #1e293b;              /* Deep blue-gray */
        --dark-light: #334155;        /* Medium blue-gray */
        --light: #f8fafc;             /* Light blue-gray */
        --gray: #64748b;              /* Neutral gray-blue */
        --gray-light: #e2e8f0;        /* Light blue-gray border */
        --card-bg: #ffffff;
        
        /* Gradients */
        --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        --gradient-primary-reverse: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%);
        --gradient-light: linear-gradient(135deg, #f0f9ff 0%, #e0f7ff 100%);
        --gradient-card: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        --gradient-image: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        --gradient-warning: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%);
        --gradient-avatar: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        
        /* Shadows with blue tint */
        --shadow-sm: 0 1px 3px rgba(30, 41, 59, 0.1);
        --shadow: 0 4px 6px -1px rgba(30, 41, 59, 0.1), 0 2px 4px -1px rgba(30, 41, 59, 0.06);
        --shadow-lg: 0 10px 15px -3px rgba(30, 41, 59, 0.1), 0 4px 6px -2px rgba(30, 41, 59, 0.05);
        --shadow-xl: 0 20px 25px -5px rgba(30, 41, 59, 0.1), 0 10px 10px -5px rgba(30, 41, 59, 0.04);
        --shadow-primary: 0 10px 15px -3px rgba(37, 99, 235, 0.2);
        --shadow-warning: 0 10px 15px -3px rgba(245, 158, 11, 0.2);
        
        /* Other Variables */
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
    
    /* Top Navigation */
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
    
    .user-avatar-img {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid white;
        box-shadow: var(--shadow);
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
    
    /* Dashboard Layout */
    .dashboard-layout {
        display: grid;
        grid-template-columns: 280px 1fr;
        gap: 30px;
        margin-bottom: 40px;
    }
    
    /* Sidebar */
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
    }
    
    .location-card {
        background: var(--gradient-light);
        border-radius: var(--radius);
        padding: 20px;
        margin-top: 20px;
        border: 1px solid var(--gray-light);
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
        margin-bottom: 15px;
    }
    
    .location-display i {
        color: var(--primary);
        font-size: 1.2rem;
    }
    
    .location-display span {
        font-weight: 600;
        color: var(--dark);
    }
    
    /* Main Content */
    .main-content {
        display: flex;
        flex-direction: column;
        gap: 30px;
    }
    
    .page-header {
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
    
    .page-header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: var(--gradient-primary);
    }
    
    .page-header h1 {
        font-size: 2.2rem;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 10px;
    }
    
    .page-header p {
        color: var(--gray);
        font-size: 1.1rem;
        max-width: 600px;
        margin: 0 auto;
    }
    
    .favorites-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 25px;
    }
    
    .favorite-card {
        background: var(--card-bg);
        border-radius: var(--radius);
        overflow: hidden;
        box-shadow: var(--shadow);
        transition: var(--transition);
        position: relative;
        border: 1px solid var(--gray-light);
        background: var(--gradient-card);
    }
    
    .favorite-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-lg);
        border-color: var(--primary-light);
    }
    
    .favorite-badge {
        position: absolute;
        top: 15px;
        right: 15px;
        background: var(--gradient-warning);
        color: white;
        width: 42px;
        height: 42px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        box-shadow: var(--shadow-warning);
        z-index: 2;
    }
    
    .parking-image {
        height: 180px;
        background: var(--gradient-image);
        position: relative;
        overflow: hidden;
    }
    
    .parking-image::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 50%;
        background: linear-gradient(to top, rgba(0,0,0,0.1), transparent);
    }
    
    .parking-content {
        padding: 25px;
    }
    
    .parking-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
    }
    
    .parking-name {
        font-size: 1.3rem;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 5px;
    }
    
    .parking-price {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--primary);
        white-space: nowrap;
        text-shadow: 0 2px 4px rgba(37, 99, 235, 0.1);
    }
    
    .parking-price small {
        font-size: 0.9rem;
        color: var(--gray);
        font-weight: 400;
    }
    
    .parking-address {
        color: var(--gray);
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.9rem;
    }
    
    .parking-address i {
        color: var(--primary);
        width: 16px;
    }
    
    .favorite-date {
        font-size: 0.85rem;
        color: var(--gray);
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    
    .favorite-date i {
        color: var(--warning);
    }
    
    .parking-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 15px;
        margin: 20px 0;
        padding: 20px 0;
        border-top: 1px solid var(--gray-light);
        border-bottom: 1px solid var(--gray-light);
    }
    
    .parking-stat {
        text-align: center;
        padding: 10px;
        transition: var(--transition);
    }
    
    .parking-stat:hover {
        background: var(--primary-soft);
        border-radius: var(--radius-sm);
    }
    
    .stat-number {
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--primary);
        margin-bottom: 5px;
    }
    
    .stat-label {
        font-size: 0.8rem;
        color: var(--gray);
        font-weight: 500;
    }
    
    .card-actions {
        display: flex;
        gap: 10px;
        margin-top: 20px;
    }
    
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 12px 24px;
        border-radius: var(--radius);
        font-weight: 600;
        font-size: 0.95rem;
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
        border: none;
        flex: 1;
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
        background: var(--gradient-warning);
        color: white;
        box-shadow: var(--shadow-sm);
    }
    
    .btn-danger:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-warning);
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
    
    .empty-state {
        text-align: center;
        padding: 80px 20px;
        background: white;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        grid-column: 1 / -1;
        border: 1px solid var(--gray-light);
        position: relative;
        overflow: hidden;
    }
    
    .empty-state::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: var(--gradient-primary);
    }
    
    .empty-state i {
        font-size: 4rem;
        color: var(--primary);
        margin-bottom: 20px;
        opacity: 0.8;
    }
    
    .empty-state h3 {
        font-size: 1.5rem;
        color: var(--dark);
        margin-bottom: 10px;
        font-weight: 700;
    }
    
    .empty-state p {
        color: var(--gray);
        margin-bottom: 20px;
        max-width: 400px;
        margin-left: auto;
        margin-right: auto;
    }
    
    /* Loading Animation */
    .loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid var(--gray-light);
        border-top: 3px solid var(--primary);
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    /* Additional Blue Elements */
    .blue-highlight {
        color: var(--primary);
        font-weight: 600;
    }
    
    .blue-divider {
        height: 1px;
        background: linear-gradient(90deg, transparent, var(--primary-light), transparent);
        margin: 25px 0;
    }
    
    @media (max-width: 1024px) {
        .dashboard-layout {
            grid-template-columns: 1fr;
        }
        
        .sidebar {
            position: static;
        }
        
        .favorites-grid {
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
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
        
        .favorites-grid {
            grid-template-columns: 1fr;
        }
        
        .card-actions {
            flex-direction: column;
        }
        
        .parking-header {
            flex-direction: column;
            gap: 10px;
            align-items: flex-start;
        }
        
        .parking-price {
            align-self: flex-start;
        }
        
        .parking-stats {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .page-header {
            padding: 20px;
        }
        
        .page-header h1 {
            font-size: 1.8rem;
        }
        
        .page-header p {
            font-size: 1rem;
        }
    }
    
    @media (max-width: 480px) {
        .parking-stats {
            grid-template-columns: 1fr;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 16px;
            font-size: 0.9rem;
        }
        
        .empty-state {
            padding: 60px 15px;
        }
        
        .empty-state h3 {
            font-size: 1.3rem;
        }
        
        .empty-state p {
            font-size: 0.9rem;
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
                        <a href="profile.php">
                            <i class="fas fa-user"></i>
                            <span>Profile</span>
                        </a>
                    </li>
                    <li>
                        <a href="favorites.php" class="active">
                            <i class="fas fa-star"></i>
                            <span>Favorites</span>
                        </a>
                    </li>
                    <li>
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
                <?php echo display_message(); ?>
                
                <div class="page-header">
                    <h1>My Favorites</h1>
                    <p>Your saved parking locations for quick access</p>
                </div>
                
                <?php if (empty($favorites)): ?>
                    <div class="empty-state">
                        <i class="fas fa-star"></i>
                        <h3>No Favorites Yet</h3>
                        <p>You haven't saved any parking locations to your favorites.</p>
                        <p style="margin-bottom: 30px;">Click the star icon on any parking location to add it here.</p>
                        <a href="find_parking.php" class="btn btn-primary">
                            <i class="fas fa-search"></i> Find Parking
                        </a>
                    </div>
                <?php else: ?>
                    <div class="favorites-grid">
                        <?php foreach ($favorites as $location): 
                            $favorite_date = new DateTime($location['favorite_date']);
                        ?>
                            <div class="favorite-card">
                                <div class="favorite-badge">
                                    <i class="fas fa-star"></i>
                                </div>
                                
                                <div class="parking-image"></div>
                                
                                <div class="parking-content">
                                    <div class="parking-header">
                                        <div>
                                            <h3 class="parking-name"><?php echo htmlspecialchars($location['name']); ?></h3>
                                            <div class="parking-address">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <?php echo htmlspecialchars($location['address']); ?>, <?php echo htmlspecialchars($location['city']); ?>
                                            </div>
                                            <div class="favorite-date">
                                                <i class="far fa-calendar"></i>
                                                Added: <?php echo $favorite_date->format('M d, Y'); ?>
                                            </div>
                                        </div>
                                        <div class="parking-price">
                                            $<?php echo number_format($location['price_per_hour'], 2); ?>
                                            <small>/hr</small>
                                        </div>
                                    </div>
                                    
                                    <div class="parking-stats">
                                        <div class="parking-stat">
                                            <div class="stat-number"><?php echo $location['available_slots']; ?></div>
                                            <div class="stat-label">Available</div>
                                        </div>
                                        <div class="parking-stat">
                                            <div class="stat-number"><?php echo $location['total_slots']; ?></div>
                                            <div class="stat-label">Total Spots</div>
                                        </div>
                                        <div class="parking-stat">
                                            <div class="stat-number">24/7</div>
                                            <div class="stat-label">Hours</div>
                                        </div>
                                    </div>
                                    
                                    <div class="card-actions">
                                        <a href="booking.php?location_id=<?php echo $location['id']; ?>" class="btn btn-primary">
                                            <i class="fas fa-calendar-check"></i> Book Now
                                        </a>
                                        
                                        <form method="POST" action="favorites.php" style="flex: 1;">
                                            <input type="hidden" name="location_id" value="<?php echo $location['id']; ?>">
                                            <input type="hidden" name="action" value="remove">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Remove from favorites?')">
                                                <i class="fas fa-trash-alt"></i> Remove
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>