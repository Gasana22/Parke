<?php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'Parke'; ?></title>
   <style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

body {
    background: #f7fafc;
    color: #2d3748;
}

.header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 1rem 2rem;
    box-shadow: 0 8px 32px rgba(0,0,0,0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    position: relative;
    overflow: hidden;
}

.header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
    transform: translateX(-100%);
}

.header:hover::before {
    transform: translateX(100%);
    transition: transform 0.8s ease;
}

.header h1 a {
    text-decoration: none;
    color: white;
    font-weight: 800;
    font-size: 1.8rem;
    text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
    transition: transform 0.3s ease;
}

.header h1 a:hover {
    transform: scale(1.05);
}

.nav-links {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.nav-links a {
    text-decoration: none;
    color: white;
    padding: 10px 20px;
    border-radius: 12px;
    transition: all 0.3s ease;
    font-weight: 500;
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    position: relative;
    overflow: hidden;
}

.nav-links a::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
    transition: left 0.5s ease;
}

.nav-links a:hover::before {
    left: 100%;
}

.nav-links a:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.2);
}

.container {
    max-width: 1200px;
    margin: 2rem auto;
    padding: 0 2rem;
}

.btn {
    padding: 12px 24px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    text-decoration: none;
    border-radius: 12px;
    border: none;
    cursor: pointer;
    display: inline-block;
    text-align: center;
    transition: all 0.3s ease;
    font-weight: 600;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
}

.notification-badge {
    background: linear-gradient(135deg, #f56565, #e53e3e);
    color: white;
    border-radius: 50%;
    padding: 4px 8px;
    font-size: 0.75rem;
    font-weight: 700;
    margin-left: 5px;
    box-shadow: 0 2px 8px rgba(245, 101, 101, 0.4);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.1); }
    100% { transform: scale(1); }
}

@media (max-width: 768px) {
    .header {
        flex-direction: column;
        gap: 1rem;
        padding: 1rem;
    }
    
    .nav-links {
        justify-content: center;
        width: 100%;
    }
    
    .nav-links a {
        flex: 1;
        text-align: center;
        min-width: 120px;
    }
}
</style>
</head>
<body>
    <div class="header">
        <h1><a href="driver_dashboard.php" style="text-decoration: none; color: inherit;">Parke 🚗</a></h1>
        <div class="nav-links">
            <a href="find_parking.php">Find Parking</a>
            <a href="my_reservations.php">My Reservations</a>
            <a href="payment.php">Payment Methods</a>
            <a href="notifications.php">
                Notifications
                <?php 
                if (isset($pdo) && isset($_SESSION['user_id'])) {
                    $notification_stmt = $pdo->prepare("SELECT COUNT(*) as notification_count FROM notifications WHERE user_id = ? AND is_read = 0");
                    $notification_stmt->execute([$_SESSION['user_id']]);
                    $unread_notifications = $notification_stmt->fetch()['notification_count'];
                    if ($unread_notifications > 0): ?>
                        <span class="notification-badge"><?php echo $unread_notifications; ?></span>
                    <?php endif;
                } ?>
            </a>
            <a href="logout.php">Logout</a>
        </div>
    </div>