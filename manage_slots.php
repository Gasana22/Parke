<?php
session_start(); 
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'operator') {
    header("Location: index.php");
    exit();
}

$operator_id = $_SESSION['user_id'];
$operator_name = $_SESSION['username'];
$company_name = $_SESSION['company_name'] ?? $operator_name . ' Parking';
$success_message = $error_message = '';

$lot_stmt = $pdo->prepare("SELECT * FROM parking_lots WHERE operator_id = ? LIMIT 1");
$lot_stmt->execute([$operator_id]);
$parking_lot = $lot_stmt->fetch();

if (!$parking_lot) {
    try {
        $default_lot_name = $company_name . " Lot";
        $default_address = "Parking facility managed by " . $operator_name;
        
        $create_stmt = $pdo->prepare("
            INSERT INTO parking_lots 
            (name, address, total_slots, available_slots, price_per_hour, operator_id) 
            VALUES (?, ?, 0, 0, 5.00, ?)
        ");
        $create_stmt->execute([$default_lot_name, $default_address, $operator_id]);
        
        $parking_lot_id = $pdo->lastInsertId();
        
        $lot_stmt->execute([$operator_id]);
        $parking_lot = $lot_stmt->fetch();
        
        $success_message = "Welcome! Your parking lot has been created. Start by adding parking slots.";
        
    } catch (PDOException $e) {
        $error_message = "Error creating parking lot: " . $e->getMessage();
    }
}

if ($parking_lot) {
    $selected_lot_id = $parking_lot['id'];
    
    $slots_stmt = $pdo->prepare("
        SELECT 
            ps.*, 
            r.id as reservation_id, 
            r.status as reservation_status,
            r.start_time,
            r.end_time,
            u.username as reserved_by
        FROM parking_slots ps 
        LEFT JOIN reservations r ON ps.id = r.parking_slot_id AND r.status = 'active'
        LEFT JOIN drivers u ON r.user_id = u.id
        WHERE ps.parking_lot_id = ?
        ORDER BY 
            CASE 
                WHEN ps.slot_number REGEXP '^[A-Za-z]' THEN 1
                ELSE 2
            END,
            ps.slot_number
    ");
    $slots_stmt->execute([$selected_lot_id]);
    $slots = $slots_stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_slot'])) {
        $slot_id = $_POST['slot_id'];
        $is_available = $_POST['is_available'];
        $slot_type = $_POST['slot_type'];
        
        try {
            $update_stmt = $pdo->prepare("UPDATE parking_slots SET is_available = ?, slot_type = ? WHERE id = ?");
            $update_stmt->execute([$is_available, $slot_type, $slot_id]);
            
            $count_stmt = $pdo->prepare("
                UPDATE parking_lots 
                SET available_slots = (
                    SELECT COUNT(*) FROM parking_slots 
                    WHERE parking_lot_id = ? AND is_available = 1
                ) 
                WHERE id = ?
            ");
            $count_stmt->execute([$selected_lot_id, $selected_lot_id]);
            
            $success_message = "Slot status updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Error updating slot: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['add_slot'])) {
        $slot_number = $_POST['new_slot_number'];
        $slot_type = $_POST['new_slot_type'];
        
        try {
            $check_stmt = $pdo->prepare("SELECT id FROM parking_slots WHERE parking_lot_id = ? AND slot_number = ?");
            $check_stmt->execute([$selected_lot_id, $slot_number]);
            
            if ($check_stmt->rowCount() > 0) {
                $error_message = "Slot number $slot_number already exists in this parking lot!";
            } else {
                $add_stmt = $pdo->prepare("INSERT INTO parking_slots (parking_lot_id, slot_number, slot_type, is_available) VALUES (?, ?, ?, 1)");
                $add_stmt->execute([$selected_lot_id, $slot_number, $slot_type]);
                
                $update_lot_stmt = $pdo->prepare("
                    UPDATE parking_lots 
                    SET total_slots = total_slots + 1, 
                        available_slots = available_slots + 1 
                    WHERE id = ?
                ");
                $update_lot_stmt->execute([$selected_lot_id]);
                
                $success_message = "New slot added successfully!";
                header("Location: manage_slots.php");
                exit();
            }
        } catch (PDOException $e) {
            $error_message = "Error adding slot: " . $e->getMessage();
        }
    }
}

$stats = [
    'total_slots' => 0,
    'available_slots' => 0,
    'occupied_slots' => 0,
    'reserved_slots' => 0
];

if ($parking_lot && isset($slots)) {
    $stats['total_slots'] = count($slots);
    $stats['available_slots'] = count(array_filter($slots, function($slot) {
        return $slot['is_available'] && !$slot['reservation_id'];
    }));
    $stats['occupied_slots'] = count(array_filter($slots, function($slot) {
        return !$slot['is_available'] && !$slot['reservation_id'];
    }));
    $stats['reserved_slots'] = count(array_filter($slots, function($slot) {
        return $slot['reservation_id'];
    }));
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Parking Slots - Parke</title>
    <style>
.manage-slots-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 1rem;
}

.manage-header {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 1.5rem 2rem;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    margin-bottom: 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.manage-title {
    font-size: 2.2rem;
    font-weight: 800;
    background: linear-gradient(135deg, #4361ee, #7209b7);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.manage-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 2.5rem;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    margin-bottom: 2rem;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.welcome-message {
    background: linear-gradient(135deg, #e3f2fd, #bbdefb);
    padding: 2rem;
    border-radius: 16px;
    margin-bottom: 2rem;
    border-left: 6px solid #2196F3;
    animation: slideIn 0.6s ease-out;
}

.welcome-message h3 {
    color: #1976D2;
    margin-bottom: 1rem;
    font-size: 1.5rem;
}

.lot-info {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    padding: 2rem;
    border-radius: 16px;
    margin-bottom: 2rem;
    border-left: 6px solid #4361ee;
}

.lot-info h2 {
    color: #2d3748;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.slots-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
    margin: 2rem 0;
}

.stat-card {
    background: white;
    padding: 2rem;
    border-radius: 16px;
    text-align: center;
    box-shadow: var(--shadow);
    transition: var(--transition);
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
}

.stat-total::before { background: linear-gradient(135deg, #2196F3, #1976D2); }
.stat-available::before { background: linear-gradient(135deg, #4CAF50, #45a049); }
.stat-occupied::before { background: linear-gradient(135deg, #F44336, #d32f2f); }
.stat-reserved::before { background: linear-gradient(135deg, #FF9800, #e68900); }

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-hover);
}

.stat-number {
    font-size: 2.5rem;
    font-weight: 800;
    margin-bottom: 0.5rem;
}

.stat-total .stat-number { color: #2196F3; }
.stat-available .stat-number { color: #4CAF50; }
.stat-occupied .stat-number { color: #F44336; }
.stat-reserved .stat-number { color: #FF9800; }

.add-slot-form {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    padding: 2.5rem;
    border-radius: 16px;
    margin-bottom: 2rem;
    border-left: 6px solid #4361ee;
}

.add-slot-form h3 {
    color: #2d3748;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
    align-items: end;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.form-label {
    font-weight: 600;
    color: #4a5568;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-input, .form-select {
    padding: 1rem 1.2rem;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    font-size: 1rem;
    transition: var(--transition);
    background: white;
}

.form-input:focus, .form-select:focus {
    outline: none;
    border-color: #4361ee;
    box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
}

.slot-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.5rem;
    margin-top: 2rem;
}

.slot-card {
    border: 3px solid;
    border-radius: 16px;
    padding: 1.5rem;
    text-align: center;
    transition: var(--transition);
    background: white;
    position: relative;
    overflow: hidden;
}

.slot-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
}

.slot-card.available {
    border-color: #4CAF50;
    background: linear-gradient(135deg, #f8fff8, #e8f5e8);
}

.slot-card.available::before {
    background: linear-gradient(135deg, #4CAF50, #45a049);
}

.slot-card.occupied {
    border-color: #F44336;
    background: linear-gradient(135deg, #fff8f8, #ffebee);
}

.slot-card.occupied::before {
    background: linear-gradient(135deg, #F44336, #d32f2f);
}

.slot-card.reserved {
    border-color: #FF9800;
    background: linear-gradient(135deg, #fffbf0, #fff3e0);
}

.slot-card.reserved::before {
    background: linear-gradient(135deg, #FF9800, #e68900);
}

.slot-card:hover {
    transform: translateY(-8px);
    box-shadow: var(--shadow-hover);
}

.slot-number {
    font-size: 1.5rem;
    font-weight: 800;
    margin-bottom: 1rem;
    color: #2d3748;
}

.slot-status {
    padding: 0.75rem 1.5rem;
    border-radius: 25px;
    font-size: 0.9rem;
    font-weight: 700;
    margin-bottom: 1rem;
    display: inline-block;
}

.status-available { 
    background: linear-gradient(135deg, #4CAF50, #45a049);
    color: white;
}

.status-occupied { 
    background: linear-gradient(135deg, #F44336, #d32f2f);
    color: white;
}

.status-reserved { 
    background: linear-gradient(135deg, #FF9800, #e68900);
    color: white;
}

.slot-type {
    margin: 1rem 0;
    font-weight: 600;
    color: #4a5568;
}

.slot-form {
    margin-top: 1rem;
}

.slot-form select {
    width: 100%;
    padding: 0.75rem;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    margin-bottom: 0.75rem;
    font-size: 0.9rem;
    transition: var(--transition);
}

.slot-form select:focus {
    outline: none;
    border-color: #4361ee;
}

.btn {
    padding: 1rem 1.5rem;
    border: none;
    border-radius: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    font-size: 1rem;
}

.btn-primary {
    background: linear-gradient(135deg, #4361ee, #7209b7);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(67, 97, 238, 0.3);
}

.btn-success {
    background: linear-gradient(135deg, #4CAF50, #45a049);
    color: white;
}

.btn-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(76, 175, 80, 0.3);
}

.btn-small {
    padding: 0.75rem 1rem;
    font-size: 0.9rem;
    width: 100%;
}

.message {
    padding: 1.5rem;
    border-radius: 12px;
    margin-bottom: 2rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    animation: slideIn 0.5s ease-out;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.success {
    background: linear-gradient(135deg, rgba(76, 175, 80, 0.1), rgba(69, 160, 73, 0.1));
    color: #155724;
    border-left: 4px solid #4CAF50;
}

.error {
    background: linear-gradient(135deg, rgba(244, 67, 54, 0.1), rgba(211, 47, 47, 0.1));
    color: #721c24;
    border-left: 4px solid #F44336;
}

.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #718096;
}

.empty-state h3 {
    font-size: 1.5rem;
    margin-bottom: 1rem;
    color: #4a5568;
}

@media (max-width: 1024px) {
    .manage-header {
        flex-direction: column;
        gap: 1.5rem;
        text-align: center;
    }
}

@media (max-width: 768px) {
    .manage-slots-container {
        padding: 0 0.5rem;
    }
    
    .manage-card {
        padding: 1.5rem;
    }
    
    .slots-stats-grid {
        grid-template-columns: 1fr;
    }
    
    .slot-grid {
        grid-template-columns: 1fr;
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .manage-title {
        font-size: 1.8rem;
    }
}
    </style>
</head>
<body>
    <div class="header">
        <h1>Manage Parking Slots</h1>
        <div class="nav-links">
            <a href="operator_dashboard.php">← Dashboard</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <?php if ($success_message): ?>
            <div class="message success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="message error"><?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <div class="card">
            <?php if ($parking_lot): ?>
                
                <?php if (empty($slots)): ?>
                    <div class="welcome-message">
                        <h3>🎉 Welcome to Your Parking Management System!</h3>
                        <p>Your parking lot <strong>"<?php echo htmlspecialchars($parking_lot['name']); ?>"</strong> is ready.</p>
                        <p>Start by adding your first parking slot below.</p>
                    </div>
                <?php endif; ?>

                <div class="lot-info">
                    <h2>🏢 <?php echo htmlspecialchars($parking_lot['name']); ?></h2>
                    <p><strong>Address:</strong> <?php echo htmlspecialchars($parking_lot['address']); ?></p>
                    <p><strong>Hourly Rate:</strong> $<?php echo number_format($parking_lot['price_per_hour'] ?? 0, 2); ?></p>
                </div>

                <?php if ($slots): ?>
                <div class="stats-grid">
                    <div class="stat-card stat-total">
                        <div class="stat-number"><?php echo $stats['total_slots']; ?></div>
                        <div>Total Slots</div>
                    </div>
                    <div class="stat-card stat-available">
                        <div class="stat-number"><?php echo $stats['available_slots']; ?></div>
                        <div>Available Now</div>
                    </div>
                    <div class="stat-card stat-occupied">
                        <div class="stat-number"><?php echo $stats['occupied_slots']; ?></div>
                        <div>Occupied</div>
                    </div>
                    <div class="stat-card stat-reserved">
                        <div class="stat-number"><?php echo $stats['reserved_slots']; ?></div>
                        <div>Reserved</div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="add-slot-form">
                    <h3>➕ Add New Parking Slot</h3>
                    <form method="POST" action="">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="new_slot_number">Slot Number *</label>
                                <input type="text" id="new_slot_number" name="new_slot_number" required 
                                       placeholder="e.g., A1, B2, C3">
                            </div>
                            <div class="form-group">
                                <label for="new_slot_type">Slot Type *</label>
                                <select id="new_slot_type" name="new_slot_type" required>
                                    <option value="standard">Standard</option>
                                    <option value="compact">Compact</option>
                                    <option value="large">Large</option>
                                    <option value="disabled">Handicap</option>
                                    <option value="ev_charging">EV Charging</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <button type="submit" name="add_slot" class="btn btn-success">Add New Slot</button>
                            </div>
                        </div>
                    </form>
                </div>

                <?php if ($slots): ?>
                    <h3>🅿️ Your Parking Slots</h3>
                    <div class="slot-grid">
                        <?php foreach ($slots as $slot): 
                            $status_class = '';
                            $status_text = '';
                            if ($slot['reservation_id']) {
                                $status_class = 'reserved';
                                $status_text = 'Reserved';
                            } else {
                                $status_class = $slot['is_available'] ? 'available' : 'occupied';
                                $status_text = $slot['is_available'] ? 'Available' : 'Occupied';
                            }
                        ?>
                        <div class="slot-card <?php echo $status_class; ?>">
                            <div class="slot-number">Slot #<?php echo $slot['slot_number']; ?></div>
                            <div class="slot-status status-<?php echo $status_class; ?>">
                                <?php echo $status_text; ?>
                            </div>
                            
                            <div style="margin: 0.5rem 0; font-size: 0.9rem;">
                                <strong>Type:</strong> <?php echo ucfirst(str_replace('_', ' ', $slot['slot_type'])); ?>
                            </div>

                            <form method="POST" action="" style="margin-top: 0.5rem;">
                                <input type="hidden" name="slot_id" value="<?php echo $slot['id']; ?>">
                                <select name="is_available" style="margin-bottom: 0.5rem; font-size: 0.8rem; width: 100%;">
                                    <option value="1" <?php echo $slot['is_available'] ? 'selected' : ''; ?>>Available</option>
                                    <option value="0" <?php echo !$slot['is_available'] ? 'selected' : ''; ?>>Occupied</option>
                                </select>
                                <select name="slot_type" style="margin-bottom: 0.5rem; font-size: 0.8rem; width: 100%;">
                                    <option value="standard" <?php echo $slot['slot_type'] == 'standard' ? 'selected' : ''; ?>>Standard</option>
                                    <option value="compact" <?php echo $slot['slot_type'] == 'compact' ? 'selected' : ''; ?>>Compact</option>
                                    <option value="large" <?php echo $slot['slot_type'] == 'large' ? 'selected' : ''; ?>>Large</option>
                                    <option value="disabled" <?php echo $slot['slot_type'] == 'disabled' ? 'selected' : ''; ?>>Handicap</option>
                                    <option value="ev_charging" <?php echo $slot['slot_type'] == 'ev_charging' ? 'selected' : ''; ?>>EV Charging</option>
                                </select>
                                <button type="submit" name="update_slot" class="btn btn-small" style="width: 100%;">
                                    Update Status
                                </button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 2rem; color: #666;">
                        <h3>🅿️ No Slots Yet</h3>
                        <p>Your parking lot is ready! Add your first parking slot using the form above.</p>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</body>
</html>