<?php
session_start(); 
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'operator') {
    header("Location: index.php");
    exit();
}

$operator_id = $_SESSION['user_id'];
$success_message = $error_message = '';
try {
    $pdo->query("SELECT 1 FROM pricing_rules LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pricing_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        parking_lot_id INT NOT NULL,
        day_of_week TINYINT NOT NULL,
        start_hour TINYINT NOT NULL,
        end_hour TINYINT NOT NULL,
        special_price DECIMAL(8,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (parking_lot_id) REFERENCES parking_lots(id) ON DELETE CASCADE
    )");
}

$lot_stmt = $pdo->prepare("SELECT id, name, COALESCE(price_per_hour, 2000) as price_per_hour FROM parking_lots WHERE operator_id = ? LIMIT 1");
$lot_stmt->execute([$operator_id]);
$parking_lot = $lot_stmt->fetch();

if (!$parking_lot) {
    try {
        $default_lot_name = $_SESSION['company_name'] ?? $_SESSION['username'] . " Parking";
        $default_address = "Parking facility managed by " . $_SESSION['username'];
        
        $create_stmt = $pdo->prepare("
            INSERT INTO parking_lots 
            (name, address, total_slots, available_slots, price_per_hour, operator_id) 
            VALUES (?, ?, 0, 0, 2000, ?)
        ");
        $create_stmt->execute([$default_lot_name, $default_address, $operator_id]);
        
        $lot_stmt->execute([$operator_id]);
        $parking_lot = $lot_stmt->fetch();
        
    } catch (PDOException $e) {
        $error_message = "Error creating parking lot: " . $e->getMessage();
    }
}

$selected_lot_id = $parking_lot['id'] ?? null;
$pricing_rules = [];

if ($selected_lot_id) {
    try {
        $rules_stmt = $pdo->prepare("SELECT * FROM pricing_rules WHERE parking_lot_id = ? ORDER BY day_of_week, start_hour");
        $rules_stmt->execute([$selected_lot_id]);
        $pricing_rules = $rules_stmt->fetchAll();
    } catch (PDOException $e) {
        $error_message = "Error loading pricing rules: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_base_rate'])) {
    $price_per_hour = $_POST['price_per_hour'];
    
    try {
        $update_stmt = $pdo->prepare("UPDATE parking_lots SET price_per_hour = ? WHERE id = ?");
        $update_stmt->execute([$price_per_hour, $selected_lot_id]);
        $success_message = "Base hourly rate updated successfully!";
        
        $lot_stmt->execute([$operator_id]);
        $parking_lot = $lot_stmt->fetch();
    } catch (PDOException $e) {
        $error_message = "Error updating base rate: " . $e->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_pricing_rule'])) {
    $day_of_week = $_POST['day_of_week'];
    $start_hour = $_POST['start_hour'];
    $end_hour = $_POST['end_hour'];
    $special_price = $_POST['special_price'];
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO pricing_rules (parking_lot_id, day_of_week, start_hour, end_hour, special_price) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$selected_lot_id, $day_of_week, $start_hour, $end_hour, $special_price]);
        $success_message = "Special pricing rule added successfully!";
        
        header("Location: dynamic_pricing.php");
        exit();
    } catch (PDOException $e) {
        $error_message = "Error saving pricing rule: " . $e->getMessage();
    }
}
if (isset($_GET['delete_rule'])) {
    $rule_id = $_GET['delete_rule'];
    
    try {
        $delete_stmt = $pdo->prepare("DELETE FROM pricing_rules WHERE id = ?");
        $delete_stmt->execute([$rule_id]);
        $success_message = "Pricing rule deleted successfully!";
        header("Location: dynamic_pricing.php");
        exit();
    } catch (PDOException $e) {
        $error_message = "Error deleting pricing rule: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Parking Rates - Parke</title>
   <style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

body {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #2d3748;
    min-height: 100vh;
}

.header {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 1rem 2rem;
    box-shadow: 0 8px 32px rgba(0,0,0,0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid rgba(255, 255, 255, 0.2);
}

.nav-links {
    display: flex;
    gap: 0.5rem;
}

.nav-links a {
    text-decoration: none;
    color: #4a5568;
    padding: 10px 20px;
    border-radius: 12px;
    transition: all 0.3s ease;
    font-weight: 500;
    border: 1px solid transparent;
}

.nav-links a:hover {
    background: rgba(66, 153, 225, 0.1);
    color: #2b6cb0;
    border-color: rgba(66, 153, 225, 0.2);
    transform: translateY(-2px);
}

.container {
    max-width: 1000px;
    margin: 2rem auto;
    padding: 0 2rem;
}

.card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 2rem;
    border-radius: 20px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
    border: 1px solid rgba(255, 255, 255, 0.2);
    transition: transform 0.3s ease;
}

.card:hover {
    transform: translateY(-5px);
}

.lot-info {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 2rem;
    border-radius: 16px;
    margin-bottom: 2rem;
    color: white;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
}

.lot-info h2 {
    font-size: 1.8rem;
    margin-bottom: 0.5rem;
    font-weight: 700;
}

.base-rate-section {
    background: linear-gradient(135deg, #c6f6d5, #9ae6b4);
    padding: 2rem;
    border-radius: 16px;
    margin-bottom: 2rem;
    border: none;
    box-shadow: 0 10px 30px rgba(72, 187, 120, 0.2);
    position: relative;
    overflow: hidden;
}

.base-rate-section::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #48bb78, #38a169);
}

.special-rate-section {
    background: linear-gradient(135deg, #bee3f8, #90cdf4);
    padding: 2rem;
    border-radius: 16px;
    margin-bottom: 2rem;
    border: none;
    box-shadow: 0 10px 30px rgba(66, 153, 225, 0.2);
    position: relative;
    overflow: hidden;
}

.special-rate-section::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #4299e1, #3182ce);
}

select, input {
    padding: 12px 16px;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    font-size: 1rem;
    transition: all 0.3s ease;
    background: white;
    width: 100%;
}

select:focus, input:focus {
    border-color: #4299e1;
    outline: none;
    box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
    transform: translateY(-2px);
}

.btn {
    padding: 12px 24px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    text-decoration: none;
    border-radius: 12px;
    border: none;
    cursor: pointer;
    font-size: 1rem;
    font-weight: 600;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
}

.btn-success {
    background: linear-gradient(135deg, #48bb78, #38a169);
    box-shadow: 0 4px 15px rgba(72, 187, 120, 0.3);
}

.btn-danger {
    background: linear-gradient(135deg, #f56565, #e53e3e);
    box-shadow: 0 4px 15px rgba(245, 101, 101, 0.3);
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
    align-items: end;
}

.form-group {
    margin-bottom: 1.5rem;
}

label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #2d3748;
}

.message {
    padding: 1rem 1.5rem;
    border-radius: 12px;
    margin-bottom: 1.5rem;
    font-weight: 500;
    border: none;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.success {
    background: linear-gradient(135deg, #c6f6d5, #9ae6b4);
    color: #22543d;
}

.error {
    background: linear-gradient(135deg, #fed7d7, #feb2b2);
    color: #742a2a;
}

.pricing-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1rem;
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.pricing-table th, .pricing-table td {
    padding: 1rem;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
}

.pricing-table th {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-size: 0.9rem;
}

.pricing-table tr:hover {
    background: rgba(66, 153, 225, 0.05);
}

.currency {
    font-weight: bold;
    color: #2d3748;
    background: rgba(255, 255, 255, 0.8);
    padding: 2px 6px;
    border-radius: 6px;
    font-size: 0.8rem;
}

small {
    color: #718096;
    font-size: 0.85rem;
    margin-top: 0.5rem;
    display: block;
}

h3 {
    margin-bottom: 1.5rem;
    color: #2d3748;
    font-weight: 700;
    font-size: 1.4rem;
}
</style>
</head>
<body>
    <div class="header">
        <h1>Parking Rates</h1>
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
        
        <?php if ($parking_lot): ?>
            <div class="card">
                <div class="lot-info">
                    <h2>🏢 <?php echo htmlspecialchars($parking_lot['name']); ?></h2>
                    <p>Manage your parking rates and special pricing periods.</p>
                </div>
                <div class="base-rate-section">
                    <h3>Standard Hourly Rate</h3>
                    <form method="POST" action="">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="price_per_hour">Hourly Rate <span class="currency">(UGX)</span></label>
                                <input type="number" id="price_per_hour" name="price_per_hour" 
                                       value="<?php echo $parking_lot['price_per_hour']; ?>" 
                                       step="100" min="500" max="50000" required>
                            </div>
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <button type="submit" name="update_base_rate" class="btn">Update Standard Rate</button>
                            </div>
                        </div>
                        <small>Enter amount in Ugandan Shillings (UGX)</small>
                    </form>
                </div>

                <div class="special-rate-section">
                    <h3>Add Special Time Pricing</h3>
                    <form method="POST" action="">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="day_of_week">Day of Week</label>
                                <select id="day_of_week" name="day_of_week" required>
                                    <option value="0">Sunday</option>
                                    <option value="1">Monday</option>
                                    <option value="2">Tuesday</option>
                                    <option value="3">Wednesday</option>
                                    <option value="4">Thursday</option>
                                    <option value="5">Friday</option>
                                    <option value="6">Saturday</option>
                                    <option value="7">Everyday</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="start_hour">Start Hour (0-23)</label>
                                <input type="number" id="start_hour" name="start_hour" min="0" max="23" required placeholder="8">
                            </div>
                            <div class="form-group">
                                <label for="end_hour">End Hour (0-23)</label>
                                <input type="number" id="end_hour" name="end_hour" min="0" max="23" required placeholder="18">
                            </div>
                            <div class="form-group">
                                <label for="special_price">Special Price <span class="currency">(UGX per hour)</span></label>
                                <input type="number" id="special_price" name="special_price" step="100" min="500" max="50000" required placeholder="3000">
                            </div>
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <button type="submit" name="save_pricing_rule" class="btn btn-success">Add Special Pricing</button>
                            </div>
                        </div>
                        <small>Enter amount in Ugandan Shillings (UGX)</small>
                    </form>
                </div>

                <?php if ($pricing_rules): ?>
                    <div>
                        <h3>Active Special Pricing</h3>
                        <table class="pricing-table">
                            <thead>
                                <tr>
                                    <th>Day</th>
                                    <th>Time</th>
                                    <th>Special Price</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pricing_rules as $rule): 
                                    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Everyday'];
                                ?>
                                <tr>
                                    <td><?php echo $days[$rule['day_of_week']]; ?></td>
                                    <td><?php echo sprintf('%02d:00 - %02d:00', $rule['start_hour'], $rule['end_hour']); ?></td>
                                    <td><strong><span class="currency">UGX <?php echo number_format($rule['special_price'], 0); ?></span>/hr</strong></td>
                                    <td>
                                        <a href="dynamic_pricing.php?delete_rule=<?php echo $rule['id']; ?>" 
                                           class="btn btn-danger" 
                                           onclick="return confirm('Are you sure you want to delete this pricing rule?')">
                                            Delete
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="message error">
                    <h3>🚫 No Parking Lot Found</h3>
                    <p>Unable to load your parking lot information. Please try again later.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>