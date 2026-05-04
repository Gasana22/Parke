<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$conn = getConnection();

// Get location ID if specified
$location_id = isset($_GET['location']) ? (int)$_GET['location'] : 0;

// Handle slot updates
if (isset($_POST['update_slot'])) {
    $slot_id = (int)$_POST['slot_id'];
    $status = $conn->real_escape_string($_POST['status']);
    $slot_type = $conn->real_escape_string($_POST['slot_type']);
    
    $conn->query("UPDATE parking_slots SET 
        status = '$status', 
        slot_type = '$slot_type' 
        WHERE id = $slot_id");
    
    // Update available_slots count in location
    if ($location_id > 0) {
        $available_slots = $conn->query("
            SELECT COUNT(*) as count 
            FROM parking_slots 
            WHERE location_id = $location_id AND status = 'available'
        ")->fetch_assoc()['count'];
        
        $conn->query("UPDATE parking_locations SET available_slots = $available_slots WHERE id = $location_id");
    }
}

// Get slots with location info
$query = "SELECT ps.*, pl.name as location_name, pl.total_slots, pl.available_slots 
          FROM parking_slots ps 
          JOIN parking_locations pl ON ps.location_id = pl.id";
          
if ($location_id > 0) {
    $query .= " WHERE ps.location_id = $location_id";
}

$query .= " ORDER BY ps.location_id, ps.slot_number";
$slots = $conn->query($query);

// Get locations for filter
$locations = $conn->query("SELECT * FROM parking_locations ORDER BY name");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Slots - PARKE Admin</title>
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
        
        .filters {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 5px;
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
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .slot-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .slot-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border: 3px solid #e9ecef;
            transition: transform 0.3s;
        }
        
        .slot-card:hover {
            transform: translateY(-5px);
        }
        
        .slot-card.available {
            border-color: #28a745;
        }
        
        .slot-card.occupied {
            border-color: #dc3545;
        }
        
        .slot-card.maintenance {
            border-color: #ffc107;
        }
        
        .slot-card.reserved {
            border-color: #007bff;
        }
        
        .slot-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .slot-name {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }
        
        .location-name {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .slot-type {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 10px;
            display: inline-block;
        }
        
        .type-standard {
            background: #e9ecef;
            color: #495057;
        }
        
        .type-disabled {
            background: #007bff;
            color: white;
        }
        
        .type-electric {
            background: #28a745;
            color: white;
        }
        
        .slot-status {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 15px;
            display: inline-block;
        }
        
        .status-available {
            background: #d4edda;
            color: #155724;
        }
        
        .status-occupied {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-maintenance {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-reserved {
            background: #cce5ff;
            color: #004085;
        }
        
        .slot-form {
            margin-top: 15px;
        }
        
        .form-select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .no-slots {
            grid-column: 1/-1;
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 10px;
        }
        
        .location-info {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        @media (max-width: 768px) {
            .slot-grid {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
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
        <h1><i class="fas fa-parking"></i> Manage Parking Slots</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['admin_name']); ?></span>
            <a href="dashboard.php" class="logout-btn"><i class="fas fa-arrow-left"></i> Dashboard</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
    
    <div class="container">
        <div class="filters">
            <div class="filter-group">
                <label>Filter by Location</label>
                <select class="form-control" onchange="if(this.value) window.location.href='?location='+this.value">
                    <option value="">All Locations</option>
                    <?php 
                    $locations->data_seek(0); // Reset pointer
                    while($loc = $locations->fetch_assoc()): ?>
                        <option value="<?php echo $loc['id']; ?>" 
                            <?php echo $location_id == $loc['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($loc['name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <a href="locations.php" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-plus"></i> Add New Location
                </a>
            </div>
        </div>
        
        <?php if ($location_id > 0): 
            $location_info = $conn->query("SELECT * FROM parking_locations WHERE id = $location_id")->fetch_assoc();
            if ($location_info): ?>
                <div class="location-info">
                    <h2><?php echo htmlspecialchars($location_info['name']); ?></h2>
                    <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($location_info['address'] . ', ' . $location_info['city']); ?></p>
                    <div style="display: flex; gap: 30px; margin-top: 15px;">
                        <div>
                            <span style="font-size: 24px; font-weight: bold; color: #667eea;">
                                <?php echo $location_info['total_slots']; ?>
                            </span>
                            <div style="font-size: 14px; color: #666;">Total Slots</div>
                        </div>
                        <div>
                            <span style="font-size: 24px; font-weight: bold; color: #28a745;">
                                <?php echo $location_info['available_slots']; ?>
                            </span>
                            <div style="font-size: 14px; color: #666;">Available</div>
                        </div>
                        <div>
                            <?php $occupied = $location_info['total_slots'] - $location_info['available_slots']; ?>
                            <span style="font-size: 24px; font-weight: bold; color: #dc3545;">
                                <?php echo $occupied; ?>
                            </span>
                            <div style="font-size: 14px; color: #666;">Occupied</div>
                        </div>
                        <div>
                            <span style="font-size: 24px; font-weight: bold; color: #ffc107;">
                                $<?php echo number_format($location_info['price_per_hour'], 2); ?>
                            </span>
                            <div style="font-size: 14px; color: #666;">Price per hour</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <div class="slot-grid">
            <?php if ($slots && $slots->num_rows > 0): ?>
                <?php while($slot = $slots->fetch_assoc()): ?>
                    <div class="slot-card <?php echo $slot['status']; ?>">
                        <div class="slot-header">
                            <div class="slot-name">#<?php echo htmlspecialchars($slot['slot_number']); ?></div>
                            <?php if (!empty($slot['current_vehicle'])): ?>
                                <div style="font-size: 12px; background: #f0f0f0; padding: 2px 8px; border-radius: 10px;">
                                    <i class="fas fa-car"></i> <?php echo htmlspecialchars($slot['current_vehicle']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="location-name">
                            <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($slot['location_name']); ?>
                        </div>
                        
                        <div class="slot-type type-<?php echo $slot['slot_type']; ?>">
                            <?php echo ucfirst($slot['slot_type']); ?> Slot
                        </div>
                        
                        <div class="slot-status status-<?php echo $slot['status']; ?>">
                            <?php echo ucfirst($slot['status']); ?>
                        </div>
                        
                        <?php if ($slot['reserved_until']): ?>
                            <div style="font-size: 12px; color: #666; margin-top: 5px;">
                                <i class="fas fa-clock"></i> Reserved until: 
                                <?php echo date('M d, H:i', strtotime($slot['reserved_until'])); ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" class="slot-form">
                            <input type="hidden" name="slot_id" value="<?php echo $slot['id']; ?>">
                            
                            <select name="slot_type" class="form-select">
                                <option value="standard" <?php echo $slot['slot_type'] == 'standard' ? 'selected' : ''; ?>>Standard</option>
                                <option value="disabled" <?php echo $slot['slot_type'] == 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                                <option value="electric" <?php echo $slot['slot_type'] == 'electric' ? 'selected' : ''; ?>>Electric</option>
                            </select>
                            
                            <select name="status" class="form-select" onchange="this.form.submit()">
                                <option value="available" <?php echo $slot['status'] == 'available' ? 'selected' : ''; ?>>Available</option>
                                <option value="occupied" <?php echo $slot['status'] == 'occupied' ? 'selected' : ''; ?>>Occupied</option>
                                <option value="maintenance" <?php echo $slot['status'] == 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                            </select>
                            
                            <input type="hidden" name="update_slot" value="1">
                            
                            <div style="margin-top: 10px;">
                                <button type="submit" class="btn" style="width: 100%; background: #667eea; color: white;">
                                    <i class="fas fa-save"></i> Update Slot
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="no-slots">
                    <i class="fas fa-parking" style="font-size: 50px; color: #ccc; margin-bottom: 20px;"></i>
                    <h3>No Slots Found</h3>
                    <p><?php echo $location_id > 0 ? 'This location has no parking slots yet.' : 'No slots found. Select a location or add a new one.'; ?></p>
                    <a href="locations.php" class="btn btn-primary" style="margin-top: 15px;">
                        <i class="fas fa-plus"></i> Add Location
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
<?php $conn->close(); ?>