<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'driver') {
    header("Location: index.php");
    exit();
}

$driver_id = $_SESSION['user_id'];

$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$status_filter = $_GET['status'] ?? 'all';

$sql = "SELECT r.*, pl.name as parking_lot_name, pl.address, ps.slot_number,
               TIMESTAMPDIFF(HOUR, r.start_time, r.end_time) as duration_hours,
               (SELECT COUNT(*) FROM reviews rev WHERE rev.parking_lot_id = pl.id AND rev.user_id = ?) as has_reviewed
        FROM reservations r 
        JOIN parking_slots ps ON r.parking_slot_id = ps.id 
        JOIN parking_lots pl ON ps.parking_lot_id = pl.id 
        WHERE r.user_id = ?";
        
$params = [$driver_id, $driver_id];

if ($status_filter !== 'all') {
    $sql .= " AND r.status = ?";
    $params[] = $status_filter;
}

if (!empty($start_date)) {
    $sql .= " AND DATE(r.start_time) >= ?";
    $params[] = $start_date;
}

if (!empty($end_date)) {
    $sql .= " AND DATE(r.start_time) <= ?";
    $params[] = $end_date;
}

$sql .= " ORDER BY r.start_time DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reservations = $stmt->fetchAll();

$total_reservations = count($reservations);
$total_spent = array_sum(array_column($reservations, 'total_price'));
$avg_duration = $total_reservations > 0 ? array_sum(array_column($reservations, 'duration_hours')) / $total_reservations : 0;

$status_counts = [
    'active' => 0,
    'completed' => 0,
    'cancelled' => 0
];

foreach ($reservations as $reservation) {
    $status_counts[$reservation['status']]++;
}

$page_title = "Reservation History - Parke";
include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'Parke'; ?></title>

<style>
.history-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 2rem;
}

.page-title {
    color: white;
    font-size: 2.5rem;
    font-weight: 800;
    margin-bottom: 2rem;
    text-align: center;
    text-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
}

.stats-overview {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1.5rem;
    margin-bottom: 3rem;
}

.stat-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 2rem;
    border-radius: 20px;
    text-align: center;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    transition: all 0.3s ease;
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
    background: linear-gradient(135deg, #4361ee, #7209b7);
}

.stat-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
}

.stat-number {
    font-size: 2.5rem;
    font-weight: 800;
    background: linear-gradient(135deg, #4361ee, #7209b7);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    margin-bottom: 0.5rem;
}

.stat-label {
    color: #718096;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 600;
}

.history-filters {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 2rem;
    border-radius: 20px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
    margin-bottom: 2rem;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
    align-items: end;
}

.filter-group label {
    display: block;
    margin-bottom: 0.8rem;
    font-weight: 600;
    color: #4a5568;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filter-group input,
.filter-group select {
    width: 100%;
    padding: 1rem 1.2rem;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    font-size: 1rem;
    transition: all 0.3s ease;
    background: white;
}

.filter-group input:focus,
.filter-group select:focus {
    outline: none;
    border-color: #4361ee;
    box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
}

.filter-actions {
    display: flex;
    gap: 1rem;
}

.history-table {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 20px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.table-responsive {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th {
    background: linear-gradient(135deg, #4361ee, #7209b7);
    color: white;
    padding: 1.5rem;
    text-align: left;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-size: 0.9rem;
}

td {
    padding: 1.5rem;
    border-bottom: 1px solid #e2e8f0;
    background: white;
}

tr:hover td {
    background: #f7fafc;
    transform: scale(1.02);
    transition: all 0.2s ease;
}

.status-badge {
    padding: 0.5rem 1rem;
    border-radius: 25px;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-badge.active {
    background: linear-gradient(135deg, #4cc9f0, #4895ef);
    color: white;
}

.status-badge.completed {
    background: linear-gradient(135deg, #38b2ac, #319795);
    color: white;
}

.status-badge.cancelled {
    background: linear-gradient(135deg, #f72585, #e53e3e);
    color: white;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
}

.btn-small {
    padding: 0.6rem;
    border-radius: 10px;
    text-decoration: none;
    transition: all 0.3s ease;
    background: rgba(67, 97, 238, 0.1);
    border: 1px solid rgba(67, 97, 238, 0.2);
}

.btn-small:hover {
    background: #4361ee;
    transform: scale(1.1);
    box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
}

.history-summary {
    padding: 2rem;
    border-top: 1px solid #e2e8f0;
    background: #f7fafc;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
}

.summary-item {
    display: flex;
    justify-content: space-between;
    padding: 1.2rem;
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    border-left: 4px solid #4361ee;
}

.no-history {
    padding: 4rem 2rem;
    text-align: center;
}

.empty-state h3 {
    font-size: 1.5rem;
    color: #4a5568;
    margin-bottom: 1rem;
}

.btn {
    padding: 1rem 2rem;
    border: none;
    border-radius: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-block;
    text-align: center;
}

.btn-primary {
    background: linear-gradient(135deg, #4361ee, #7209b7);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(67, 97, 238, 0.3);
}

.btn-secondary {
    background: #e2e8f0;
    color: #4a5568;
}

.btn-secondary:hover {
    background: #cbd5e0;
}

@media (max-width: 768px) {
    .history-container {
        padding: 1rem;
    }
    
    .stats-overview {
        grid-template-columns: 1fr;
    }
    
    .filter-row {
        grid-template-columns: 1fr;
    }
    
    .filter-actions {
        flex-direction: column;
    }
    
    th, td {
        padding: 1rem 0.5rem;
        font-size: 0.9rem;
    }
}
</style>

<div class="container">
    <h1>Reservation History</h1>
    
    <div class="stats-overview">
        <div class="stat-card">
            <div class="stat-number"><?php echo $total_reservations; ?></div>
            <div class="stat-label">Total Reservations</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">UGX<?php echo number_format($total_spent, 2); ?></div>
            <div class="stat-label">Total Spent</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($avg_duration, 1); ?>h</div>
            <div class="stat-label">Avg Duration</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $status_counts['completed']; ?></div>
            <div class="stat-label">Completed</div>
        </div>
    </div>
    
    <div class="history-filters">
        <form method="GET" class="filter-form">
            <div class="filter-row">
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                
                <div class="filter-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn">Apply Filters</button>
                    <a href="reservation_history.php" class="btn btn-secondary">Clear</a>
                </div>
            </div>
        </form>
    </div>

    <div class="history-table">
        <?php if (count($reservations) > 0): ?>
            <div class="table-responsive">
                <table id="reservationsTable">
                    <thead>
                        <tr>
                            <th>Parking Lot</th>
                            <th>Slot</th>
                            <th>Date & Time</th>
                            <th>Duration</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reservations as $reservation): ?>
                            <tr class="status-<?php echo $reservation['status']; ?>">
                                <td>
                                    <div class="parking-info">
                                        <strong><?php echo htmlspecialchars($reservation['parking_lot_name']); ?></strong>
                                        <small><?php echo htmlspecialchars($reservation['address']); ?></small>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($reservation['slot_number']); ?></td>
                                <td>
                                    <div class="datetime-info">
                                        <div><?php echo date('M j, Y', strtotime($reservation['start_time'])); ?></div>
                                        <small><?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - <?php echo date('g:i A', strtotime($reservation['end_time'])); ?></small>
                                    </div>
                                </td>
                                <td><?php echo number_format($reservation['duration_hours'], 1); ?> hours</td>
                                <td class="amount">$<?php echo number_format($reservation['total_price'], 2); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $reservation['status']; ?>">
                                        <?php echo ucfirst($reservation['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="reservation_details.php?id=<?php echo $reservation['id']; ?>" class="btn btn-small" title="View Details">
                                            👁️
                                        </a>
                                        
                                        <?php if ($reservation['status'] == 'completed' && !$reservation['has_reviewed']): ?>
                                            <a href="review.php?lot_id=<?php echo $reservation['parking_lot_id']; ?>" class="btn btn-small" title="Write Review">
                                                ⭐
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($reservation['status'] == 'active'): ?>
                                            <a href="extend_reservation.php?id=<?php echo $reservation['id']; ?>" class="btn btn-small" title="Extend Time">
                                                ⏰
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="history-summary">
                <h3>Summary</h3>
                <div class="summary-grid">
                    <div class="summary-item">
                        <span class="label">Total Reservations:</span>
                        <span class="value"><?php echo $total_reservations; ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="label">Total Amount:</span>
                        <span class="value">$<?php echo number_format($total_spent, 2); ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="label">Active Reservations:</span>
                        <span class="value"><?php echo $status_counts['active']; ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="label">Completed Reservations:</span>
                        <span class="value"><?php echo $status_counts['completed']; ?></span>
                    </div>
                </div>
            </div>
            
        <?php else: ?>
            <div class="no-history">
                <div class="empty-state">
                    <h3>No reservation history found</h3>
                    <p>
                        <?php if ($status_filter !== 'all' || !empty($start_date) || !empty($end_date)): ?>
                            Try adjusting your filters to see more results.
                        <?php else: ?>
                            You haven't made any reservations yet.
                        <?php endif; ?>
                    </p>
                    <a href="find_parking.php" class="btn">Find Parking</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function exportToCSV() {
    let csv = [];
    let headers = ["Parking Lot", "Slot", "Date", "Start Time", "End Time", "Duration", "Amount", "Status"];
    csv.push(headers.join(","));
    
    document.querySelectorAll('#reservationsTable tbody tr').forEach(row => {
        let cells = row.querySelectorAll('td');
        let rowData = [
            cells[0].querySelector('strong').textContent,
            cells[1].textContent,
            cells[2].querySelector('div').textContent,
            cells[2].querySelector('small').textContent.split(' - ')[0],
            cells[2].querySelector('small').textContent.split(' - ')[1],
            cells[3].textContent,
            cells[4].textContent.replace('$', ''),
            cells[5].textContent.trim()
        ];
        csv.push(rowData.join(","));
    });
    
    let csvContent = "data:text/csv;charset=utf-8," + csv.join("\n");
    let encodedUri = encodeURI(csvContent);
    let link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "reservation_history.csv");
    document.body.appendChild(link);
    link.click();
}

function printHistory() {
    window.print();
}

const style = document.createElement('style');
style.textContent = `
@media print {
    .header, .history-filters, .export-options, .action-buttons {
        display: none !important;
    }
    
    .container {
        margin: 0;
        padding: 0;
    }
    
    body {
        background: white;
    }
    
    .history-table {
        box-shadow: none;
    }
}
`;
document.head.appendChild(style);
</script>

</body>
</html>