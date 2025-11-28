<?php
session_start(); 
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'operator') {
    header("Location: index.php");
    exit();
}

$operator_id = $_SESSION['user_id'];
$success_message = $error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_status'])) {
        $lot_id = $_POST['lot_id'];
        $is_active = $_POST['is_active'];
        
        try {
            $update_stmt = $pdo->prepare("UPDATE parking_lots SET is_active = ? WHERE id = ? AND operator_id = ?");
            $update_stmt->execute([$is_active, $lot_id, $operator_id]);
            $success_message = "Parking lot status updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Error updating parking lot: " . $e->getMessage();
        }
    }
}

try {
    $lots_stmt = $pdo->prepare("
        SELECT 
            pl.*,
            COUNT(DISTINCT ps.id) as total_slots_count,
            SUM(CASE WHEN ps.is_available = 1 THEN 1 ELSE 0 END) as available_slots_count,
            COUNT(DISTINCT r.id) as total_reservations,
            COUNT(DISTINCT CASE WHEN r.status = 'active' THEN r.id END) as active_reservations,
            COALESCE(SUM(CASE WHEN r.status = 'completed' THEN r.total_price ELSE 0 END), 0) as total_revenue,
            COALESCE(AVG(rev.rating), 0) as average_rating
        FROM parking_lots pl
        LEFT JOIN parking_slots ps ON pl.id = ps.parking_lot_id
        LEFT JOIN reservations r ON ps.id = r.parking_slot_id
        LEFT JOIN reviews rev ON pl.id = rev.parking_lot_id
        WHERE pl.operator_id = ?
        GROUP BY pl.id
        ORDER BY pl.created_at DESC
    ");
    $lots_stmt->execute([$operator_id]);
    $parking_lots = $lots_stmt->fetchAll();
} catch (PDOException $e) {
    $error_message = "Error loading parking lots: " . $e->getMessage();
    $parking_lots = [];
}

$total_lots = count($parking_lots);
$total_revenue = array_sum(array_column($parking_lots, 'total_revenue'));
$total_reservations = array_sum(array_column($parking_lots, 'total_reservations'));
$active_reservations = array_sum(array_column($parking_lots, 'active_reservations'));
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Parking - Parke</title>
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
    max-width: 1400px;
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
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 25px 50px rgba(0,0,0,0.15);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1.5rem;
    margin: 2rem 0;
}

.stat-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 2rem;
    border-radius: 16px;
    text-align: center;
    color: white;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    transition: transform 0.3s ease;
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
    transform: translateX(-100%);
}

.stat-card:hover::before {
    transform: translateX(100%);
    transition: transform 0.6s ease;
}

.stat-card:hover {
    transform: translateY(-8px);
}

.stat-number {
    font-size: 2.5rem;
    font-weight: 800;
    margin-bottom: 0.5rem;
    text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
}

.stat-card.revenue {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
}

.stat-card.reservations {
    background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
}

.parking-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1rem;
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.parking-table th, .parking-table td {
    padding: 1rem;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
}

.parking-table th {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-size: 0.9rem;
}

.parking-table tr:hover {
    background: rgba(66, 153, 225, 0.05);
}

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-active {
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
    box-shadow: 0 4px 15px rgba(72, 187, 120, 0.3);
}

.status-inactive {
    background: linear-gradient(135deg, #f56565, #e53e3e);
    color: white;
    box-shadow: 0 4px 15px rgba(245, 101, 101, 0.3);
}

.btn {
    padding: 10px 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    text-decoration: none;
    border-radius: 12px;
    border: none;
    cursor: pointer;
    font-size: 0.85rem;
    font-weight: 600;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 5px;
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

.btn-warning {
    background: linear-gradient(135deg, #ed8936, #dd6b20);
    box-shadow: 0 4px 15px rgba(237, 137, 54, 0.3);
}

.btn-secondary {
    background: linear-gradient(135deg, #a0aec0, #718096);
    box-shadow: 0 4px 15px rgba(160, 174, 192, 0.3);
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.progress-bar {
    width: 100%;
    height: 8px;
    background: #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
    margin-top: 8px;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #48bb78, #38a169);
    border-radius: 10px;
    transition: width 0.5s ease;
}

.rating-stars {
    color: #f6ad55;
    font-size: 1.1rem;
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

.quick-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1.5rem;
    margin-top: 1.5rem;
}

.action-card {
    background: white;
    padding: 2rem;
    border-radius: 16px;
    text-align: center;
    text-decoration: none;
    color: #2d3748;
    transition: all 0.3s ease;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    position: relative;
    overflow: hidden;
}

.action-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
}

.action-card.manage::before { background: linear-gradient(90deg, #667eea, #764ba2); }
.action-card.pricing::before { background: linear-gradient(90deg, #ed8936, #dd6b20); }
.action-card.analytics::before { background: linear-gradient(90deg, #9f7aea, #805ad5); }
.action-card.reviews::before { background: linear-gradient(90deg, #48bb78, #38a169); }

.action-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 40px rgba(0,0,0,0.15);
}

.action-card h3 {
    font-size: 1.2rem;
    margin-bottom: 0.5rem;
    font-weight: 700;
}

.action-card p {
    color: #718096;
    font-size: 0.9rem;
}
</style>
</head>
<body>
    <div class="header">
        <h1>Manage Parking Lot</h1>
        <div class="nav-links">
            <a href="operator_dashboard.php">← Back to Dashboard</a>
            <a href="dynamic_pricing.php">Pricing</a>
            <a href="customer_reviews.php">Reviews</a>
            <a href="operator_analytics.php">Analytics</a>
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
            <h2>Parking Overview</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_lots; ?></div>
                    <div>Parking Lots</div>
                </div>
                <div class="stat-card revenue">
                    <div class="stat-number">$<?php echo number_format($total_revenue, 2); ?></div>
                    <div>Total Revenue</div>
                </div>
                <div class="stat-card reservations">
                    <div class="stat-number"><?php echo $total_reservations; ?></div>
                    <div>Total Reservations</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $active_reservations; ?></div>
                    <div>Active Reservations</div>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Management Tools</h2>
            <div class="quick-actions">
                <a href="manage_slots.php" class="action-card manage">
                    <h3>🅿️ Manage Slots</h3>
                    <p>Manage parking slots availability</p>
                </a>
                <a href="dynamic_pricing.php" class="action-card pricing">
                    <h3>💰 Pricing Rules</h3>
                    <p>Set dynamic pricing strategies</p>
                </a>
                <a href="customer_reviews.php" class="action-card reviews">
                    <h3>⭐ Customer Reviews</h3>
                    <p>View and respond to reviews</p>
                </a>
                <a href="operator_analytics.php" class="action-card analytics">
                    <h3>📊 View Analytics</h3>
                    <p>Performance reports & insights</p>
                </a>
            </div>
        </div>

        <div class="card">
            <h2>Your Parking Lots</h2>
            <?php if ($parking_lots): ?>
                <table class="parking-table">
                    <thead>
                        <tr>
                            <th>Parking Lot</th>
                            <th>Location</th>
                            <th>Slots</th>
                            <th>Utilization</th>
                            <th>Rating</th>
                            <th>Revenue</th>
                            <th>Reservations</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($parking_lots as $lot): 
                            $utilization = $lot['total_slots'] > 0 ? (($lot['total_slots'] - $lot['available_slots_count']) / $lot['total_slots']) * 100 : 0;
                            $rating = $lot['average_rating'] > 0 ? $lot['average_rating'] : 'No ratings';
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($lot['name']); ?></strong><br>
                                <small style="color: #666;">$<?php echo number_format($lot['price_per_hour'] ?? 0, 2); ?>/hr</small>
                            </td>
                            <td><?php echo htmlspecialchars(substr($lot['address'], 0, 30)) . '...'; ?></td>
                            <td>
                                <?php echo $lot['available_slots_count']; ?>/<?php echo $lot['total_slots']; ?> available
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $utilization; ?>%"></div>
                                </div>
                            </td>
                            <td><?php echo round($utilization, 1); ?>%</td>
                            <td>
                                <?php if ($rating !== 'No ratings'): ?>
                                    <span class="rating-stars">
                                        <?php echo str_repeat('★', floor($rating)); ?><?php echo str_repeat('☆', 5 - floor($rating)); ?>
                                    </span>
                                    (<?php echo number_format($rating, 1); ?>)
                                <?php else: ?>
                                    <?php echo $rating; ?>
                                <?php endif; ?>
                            </td>
                            <td>$<?php echo number_format($lot['total_revenue'], 2); ?></td>
                            <td>
                                <?php echo $lot['active_reservations']; ?> active<br>
                                <small><?php echo $lot['total_reservations']; ?> total</small>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo ($lot['is_active'] ?? 1) ? 'active' : 'inactive'; ?>">
                                    <?php echo ($lot['is_active'] ?? 1) ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="manage_slots.php?lot_id=<?php echo $lot['id']; ?>" class="btn" title="Manage Slots">
                                        🅿️ Slots
                                    </a>
                                    <a href="dynamic_pricing.php?lot_id=<?php echo $lot['id']; ?>" class="btn btn-warning" title="Pricing">
                                        💰 Pricing
                                    </a>
                                    <form method="POST" action="" style="display: inline;">
                                        <input type="hidden" name="lot_id" value="<?php echo $lot['id']; ?>">
                                        <input type="hidden" name="is_active" value="<?php echo ($lot['is_active'] ?? 1) ? 0 : 1; ?>">
                                        <button type="submit" name="update_status" class="btn <?php echo ($lot['is_active'] ?? 1) ? 'btn-warning' : 'btn-success'; ?>" 
                                                onclick="return confirm('Are you sure you want to <?php echo ($lot['is_active'] ?? 1) ? 'deactivate' : 'activate'; ?> this parking lot?')">
                                            <?php echo ($lot['is_active'] ?? 1) ? '⏸️ Deactivate' : '▶️ Activate'; ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="text-align: center; padding: 2rem; color: #666;">
                    <h3>🚗 No Parking Lots Assigned</h3>
                    <p>You don't have any parking lots assigned to your account yet.</p>
                    <p>Please contact the administrator to get parking lots assigned to your operator account.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>