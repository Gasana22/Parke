<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$conn = getConnection();

// Handle user actions
if (isset($_GET['toggle_status'])) {
    $user_id = (int)$_GET['toggle_status'];
    $conn->query("UPDATE users SET status = IF(status = 'active', 'inactive', 'active') WHERE id = $user_id");
}

if (isset($_GET['delete'])) {
    $user_id = (int)$_GET['delete'];
    $conn->query("DELETE FROM users WHERE id = $user_id AND user_type != 'admin'");
}

// Add full_name and status columns if they don't exist
$check_columns = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
if ($check_columns->num_rows == 0) {
    $conn->query("ALTER TABLE users 
        ADD COLUMN status ENUM('active', 'inactive') DEFAULT 'active',
        ADD COLUMN full_name VARCHAR(100)");
}

$users = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - PARKE Admin</title>
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
        
        .user-table {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #e9ecef;
        }
        
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .type-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            background: #e9ecef;
            color: #495057;
        }
        
        .type-admin {
            background: #667eea;
            color: white;
        }
        
        .type-driver {
            background: #28a745;
            color: white;
        }
        
        .action-icons {
            display: flex;
            gap: 10px;
        }
        
        .action-icons a {
            color: #666;
            text-decoration: none;
            padding: 5px;
            border-radius: 5px;
            transition: background 0.3s;
        }
        
        .action-icons a:hover {
            background: #f0f0f0;
        }
        
        .action-icons .delete-btn {
            color: #dc3545;
        }
        
        .no-users {
            text-align: center;
            padding: 40px;
        }
        
        @media (max-width: 768px) {
            .user-table {
                overflow-x: auto;
            }
            table {
                min-width: 800px;
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
        <h1><i class="fas fa-users-cog"></i> Manage Users</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['admin_name']); ?></span>
            <a href="dashboard.php" class="logout-btn"><i class="fas fa-arrow-left"></i> Dashboard</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
    
    <div class="container">
        <div class="header-actions" style="display: flex; justify-content: space-between; margin-bottom: 20px;">
            <h2>User Management</h2>
            <button class="btn" onclick="openAddUserModal()" style="background: #667eea; color: white; padding: 10px 20px; border-radius: 5px;">
                <i class="fas fa-user-plus"></i> Add User
            </button>
        </div>
        
        <div class="user-table">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User Info</th>
                        <th>Contact</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($users && $users->num_rows > 0): ?>
                        <?php while($user = $users->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($user['username']); ?></strong><br>
                                    <?php if (!empty($user['full_name'])): ?>
                                        <small><?php echo htmlspecialchars($user['full_name']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($user['email']); ?><br>
                                    <?php if (!empty($user['phone'])): ?>
                                        <small><?php echo htmlspecialchars($user['phone']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="type-badge type-<?php echo $user['user_type']; ?>">
                                        <?php echo ucfirst($user['user_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $user['status'] ?? 'active'; ?>">
                                        <?php echo ucfirst($user['status'] ?? 'active'); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                <td>
                                    <div class="action-icons">
                                        <a href="?toggle_status=<?php echo $user['id']; ?>" 
                                           title="<?php echo ($user['status'] ?? 'active') == 'active' ? 'Deactivate' : 'Activate'; ?>">
                                            <i class="fas fa-power-off"></i>
                                        </a>
                                        <a href="edit_user.php?id=<?php echo $user['id']; ?>" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if ($user['user_type'] != 'admin'): ?>
                                            <a href="?delete=<?php echo $user['id']; ?>" 
                                               onclick="return confirm('Are you sure you want to delete this user? All their bookings will also be deleted.')" 
                                               title="Delete" class="delete-btn">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="no-users">
                                <i class="fas fa-users" style="font-size: 50px; color: #ccc; margin-bottom: 20px;"></i>
                                <h3>No Users Found</h3>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <script>
        function openAddUserModal() {
            // In a real implementation, show a modal to add user
            window.location.href = 'add_user.php';
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>