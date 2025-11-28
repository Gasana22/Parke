<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'driver') {
    header("Location: index.php");
    exit();
}

$driver_id = $_SESSION['user_id'];
$parking_lot_id = $_GET['lot_id'] ?? '';

if (empty($parking_lot_id)) {
    header("Location: find_parking.php");
    exit();
}

$stmt = $pdo->prepare("SELECT pl.*, 
                      (SELECT COUNT(*) FROM parking_slots ps WHERE ps.parking_lot_id = pl.id AND ps.is_available = 1) as available_slots_count
               FROM parking_lots pl 
               WHERE pl.id = ?");
$stmt->execute([$parking_lot_id]);
$parking_lot = $stmt->fetch();

if (!$parking_lot) {
    header("Location: find_parking.php");
    exit();
}

$slots_stmt = $pdo->prepare("SELECT * FROM parking_slots WHERE parking_lot_id = ? AND is_available = 1 ORDER BY slot_number");
$slots_stmt->execute([$parking_lot_id]);
$available_slots = $slots_stmt->fetchAll();

if (isset($_POST['reserve_slot'])) {
    $slot_id = $_POST['slot_id'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $duration = $_POST['duration'];
    
    $total_price = $parking_lot['price_per_hour'] * $duration;
    
    try {
        $pdo->beginTransaction();
        
        $reservation_stmt = $pdo->prepare("INSERT INTO reservations (user_id, parking_slot_id, start_time, end_time, total_price, status) VALUES (?, ?, ?, ?, ?, 'active')");
        $reservation_stmt->execute([$driver_id, $slot_id, $start_time, $end_time, $total_price]);
        $reservation_id = $pdo->lastInsertId();
        
        $update_slot_stmt = $pdo->prepare("UPDATE parking_slots SET is_available = 0 WHERE id = ?");
        $update_slot_stmt->execute([$slot_id]);
        
        $payment_stmt = $pdo->prepare("INSERT INTO payments (user_id, reservation_id, amount, status) VALUES (?, ?, ?, 'pending')");
        $payment_stmt->execute([$driver_id, $reservation_id, $total_price]);
        
        $notification_stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'Reservation Confirmed', ?)");
        $notification_stmt->execute([$driver_id, "Your reservation at {$parking_lot['name']} has been confirmed. Total: UGX " . number_format($total_price, 2)]);
        
        $pdo->commit();
        
        header("Location: reservation_success.php?id=" . $reservation_id);
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Reservation failed: " . $e->getMessage();
    }
}

$page_title = "Reserve Parking - " . $parking_lot['name'];
include 'header.php';
?>


<!DOCTYPE html>
<html lang="en">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'Parke'; ?></title>
<style>
    :root {
        --primary: #6366f1;
        --primary-dark: #4f46e5;
        --secondary: #10b981;
        --accent: #f59e0b;
        --danger: #ef4444;
        --warning: #f59e0b;
        --dark: #1e293b;
        --light: #f8fafc;
        --gray: #64748b;
        --glass: rgba(255, 255, 255, 0.1);
        --shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
    }

    .container {
        max-width: 1400px;
        margin: 2rem auto;
        padding: 0 2rem;
    }

    h1 {
        font-size: 2.5rem;
        font-weight: 800;
        margin-bottom: 1rem;
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    .breadcrumb {
        margin-bottom: 2rem;
        color: var(--gray);
        font-size: 0.95rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .breadcrumb a {
        color: var(--primary);
        text-decoration: none;
        font-weight: 600;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 0.25rem;
    }

    .breadcrumb a:hover {
        color: var(--primary-dark);
        text-decoration: underline;
    }

    .breadcrumb span {
        color: var(--dark);
        font-weight: 600;
    }

    .reservation-layout {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 2rem;
    }

    .reservation-form-section {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        padding: 2.5rem;
        border-radius: 20px;
        box-shadow: var(--shadow);
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .form-step {
        display: none;
        animation: slideIn 0.5s ease;
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateX(20px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    .form-step.active {
        display: block;
    }

    .form-step h2 {
        font-size: 1.8rem;
        font-weight: 700;
        margin-bottom: 1.5rem;
        color: var(--dark);
        padding-bottom: 1rem;
        border-bottom: 3px solid var(--primary);
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .form-step h2::before {
        font-size: 1.5rem;
    }

    #step1 h2::before { content: '1️⃣'; }
    #step2 h2::before { content: '2️⃣'; }
    #step3 h2::before { content: '3️⃣'; }

    .slots-selection {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .slot-option input[type="radio"] {
        display: none;
    }

    .slot-option label {
        display: flex;
        align-items: center;
        gap: 1.5rem;
        padding: 1.5rem;
        border: 2px solid #e2e8f0;
        border-radius: 16px;
        cursor: pointer;
        transition: var(--transition);
        background: white;
        position: relative;
        overflow: hidden;
    }

    .slot-option label::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: var(--primary);
        transition: var(--transition);
        opacity: 0;
    }

    .slot-option input[type="radio"]:checked + label {
        border-color: var(--primary);
        background: rgba(99, 102, 241, 0.05);
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(99, 102, 241, 0.15);
    }

    .slot-option input[type="radio"]:checked + label::before {
        opacity: 1;
    }

    .slot-icon {
        font-size: 2.5rem;
        transition: var(--transition);
    }

    .slot-option input[type="radio"]:checked + label .slot-icon {
        transform: scale(1.1);
    }

    .slot-details {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .slot-details strong {
        color: var(--dark);
        font-size: 1.1rem;
        font-weight: 700;
    }

    .slot-details span {
        color: var(--gray);
        font-size: 0.9rem;
        font-weight: 500;
    }

    .time-selection {
        display: grid;
        gap: 1.5rem;
    }

    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-group label {
        display: block;
        margin-bottom: 0.75rem;
        font-weight: 600;
        color: var(--dark);
        font-size: 1rem;
    }

    .form-group input,
    .form-group select {
        width: 100%;
        padding: 14px 16px;
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        font-size: 1rem;
        transition: var(--transition);
        background: white;
    }

    .form-group input:focus,
    .form-group select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
        transform: translateY(-2px);
    }

    .time-preview {
        background: linear-gradient(135deg, #f0f4ff, #ffffff);
        padding: 2rem;
        border-radius: 16px;
        border-left: 6px solid var(--primary);
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    }

    .time-preview h4 {
        margin: 0 0 1.5rem 0;
        color: var(--dark);
        font-size: 1.2rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .time-preview h4::before {
        content: '📅';
    }

    .preview-details {
        display: grid;
        gap: 1rem;
    }

    .preview-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem 0;
        border-bottom: 1px solid #e2e8f0;
    }

    .preview-item.total {
        border-bottom: none;
        border-top: 2px solid var(--primary);
        padding-top: 1.5rem;
        font-size: 1.2rem;
        color: var(--primary);
        font-weight: 700;
    }

    .step-actions {
        display: flex;
        gap: 1rem;
        justify-content: space-between;
        margin-top: 2rem;
        flex-wrap: wrap;
    }

    .btn {
        padding: 14px 28px;
        background: var(--primary);
        color: white;
        text-decoration: none;
        border-radius: 12px;
        border: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        font-weight: 600;
        transition: var(--transition);
        font-size: 1rem;
        box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
    }

    .btn:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
    }

    .btn-large {
        padding: 16px 32px;
        font-size: 1.1rem;
    }

    .btn-small {
        padding: 10px 20px;
        font-size: 0.9rem;
    }

    .btn-secondary {
        background: var(--gray);
        box-shadow: 0 4px 15px rgba(100, 116, 139, 0.3);
    }

    .btn-secondary:hover {
        background: #475569;
        box-shadow: 0 6px 20px rgba(100, 116, 139, 0.4);
    }

    .btn-success {
        background: var(--secondary);
        box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
    }

    .btn-success:hover {
        background: #0da271;
        box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
    }

    .confirmation-details {
        display: grid;
        gap: 2rem;
    }

    .confirmation-card {
        background: linear-gradient(135deg, #f0fff4, #ffffff);
        padding: 2rem;
        border-radius: 16px;
        border-left: 6px solid var(--secondary);
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    }

    .confirmation-card h3 {
        margin: 0 0 1.5rem 0;
        color: var(--dark);
        font-size: 1.3rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .confirmation-card h3::before {
        content: '✅';
    }

    .detail-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem 0;
        border-bottom: 1px solid #e2e8f0;
    }

    .detail-row.total {
        border-bottom: none;
        border-top: 2px solid var(--secondary);
        font-size: 1.3rem;
        color: var(--secondary);
        font-weight: 700;
        padding-top: 1.5rem;
    }

    .payment-method {
        background: white;
        padding: 2rem;
        border-radius: 16px;
        border: 2px solid #f1f5f9;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    }

    .payment-method h3 {
        margin: 0 0 1rem 0;
        color: var(--dark);
        font-size: 1.3rem;
        font-weight: 700;
    }

    .payment-options {
        display: grid;
        gap: 1rem;
        margin-top: 1.5rem;
    }

    .payment-option input[type="radio"] {
        display: none;
    }

    .payment-option label {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1.5rem;
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        cursor: pointer;
        transition: var(--transition);
        background: white;
    }

    .payment-option input[type="radio"]:checked + label {
        border-color: var(--primary);
        background: rgba(99, 102, 241, 0.05);
        transform: translateY(-2px);
    }

    .payment-icon {
        font-size: 2rem;
    }

    .reservation-summary {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }

    .summary-card, .help-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        padding: 2rem;
        border-radius: 20px;
        box-shadow: var(--shadow);
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .summary-card h3, .help-card h4 {
        margin: 0 0 1.5rem 0;
        color: var(--dark);
        font-size: 1.3rem;
        font-weight: 700;
    }

    .summary-details {
        display: grid;
        gap: 1.5rem;
    }

    .summary-item {
        padding-bottom: 1.5rem;
        border-bottom: 1px solid #f1f5f9;
    }

    .summary-item:last-child {
        border-bottom: none;
    }

    .price-info {
        text-align: center;
        padding: 1.5rem;
        background: linear-gradient(135deg, #f0f4ff, #ffffff);
        border-radius: 12px;
        border: 2px solid #e2e8f0;
    }

    .price {
        font-size: 2.5rem;
        font-weight: 800;
        color: var(--primary);
        display: block;
        margin-bottom: 0.5rem;
    }

    .price-unit {
        color: var(--gray);
        font-size: 1rem;
        font-weight: 600;
    }

    .availability {
        text-align: center;
        padding: 1.5rem;
        background: linear-gradient(135deg, #f0fff4, #ffffff);
        border-radius: 12px;
        border: 2px solid #e2e8f0;
    }

    .available-count {
        font-size: 2.5rem;
        font-weight: 800;
        color: var(--secondary);
        display: block;
        margin-bottom: 0.5rem;
    }

    .amenities-list {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-top: 0.75rem;
    }

    .amenity-tag {
        background: rgba(99, 102, 241, 0.1);
        color: var(--primary);
        padding: 0.5rem 1rem;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
        border: 1px solid rgba(99, 102, 241, 0.2);
    }

    .alert-error {
        background: linear-gradient(135deg, var(--danger), #dc2626);
        color: white;
        padding: 1.5rem;
        border-radius: 12px;
        margin-bottom: 2rem;
        box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        border-left: 4px solid white;
        font-weight: 600;
    }

    .alert-warning {
        background: linear-gradient(135deg, var(--warning), #d97706);
        color: white;
        padding: 2rem;
        border-radius: 16px;
        text-align: center;
        box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
        border-left: 4px solid white;
    }

    .alert-warning h3 {
        margin: 0 0 1rem 0;
        font-size: 1.5rem;
    }

    @media (max-width: 1024px) {
        .reservation-layout {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .container {
            padding: 0 1rem;
            margin: 1rem auto;
        }
        
        h1 {
            font-size: 2rem;
        }
        
        .reservation-form-section {
            padding: 1.5rem;
        }
        
        .slots-selection {
            grid-template-columns: 1fr;
        }
        
        .step-actions {
            flex-direction: column;
        }
        
        .step-actions .btn {
            width: 100%;
            justify-content: center;
        }
        
        .summary-card, .help-card {
            padding: 1.5rem;
        }
    }

    @media (max-width: 480px) {
        .breadcrumb {
            font-size: 0.85rem;
        }
        
        .slot-option label {
            padding: 1rem;
        }
        
        .slot-icon {
            font-size: 2rem;
        }
        
        .time-preview {
            padding: 1.5rem;
        }
        
        .confirmation-card,
        .payment-method {
            padding: 1.5rem;
        }
    }
</style>

<div class="container">
    <div class="breadcrumb">
        <a href="find_parking.php">Find Parking</a> &gt; 
        <a href="parking_details.php?lot_id=<?php echo $parking_lot_id; ?>"><?php echo htmlspecialchars($parking_lot['name']); ?></a> &gt; 
        <span>Reserve</span>
    </div>

    <div class="reservation-layout">
        <div class="reservation-form-section">
            <h1>Reserve Parking at <?php echo htmlspecialchars($parking_lot['name']); ?></h1>
            
            <?php if (isset($error)): ?>
                <div class="alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if (count($available_slots) == 0): ?>
                <div class="alert-warning">
                    <h3>❌ No Available Slots</h3>
                    <p>There are currently no available parking slots at this location.</p>
                    <a href="find_parking.php" class="btn">Find Other Parking</a>
                </div>
            <?php else: ?>
                <form method="POST" class="reservation-form" id="reservationForm">
                    <div class="form-step active" id="step1">
                        <h2>1. Select Parking Slot</h2>
                        <div class="slots-selection">
                            <?php foreach ($available_slots as $slot): 
                                $slot_icons = [
                                    'standard' => '🚗',
                                    'disabled' => '♿',
                                    'large' => '🚙'
                                ];
                                $slot_icon = $slot_icons[$slot['slot_type']] ?? '🚗';
                            ?>
                                <div class="slot-option">
                                    <input type="radio" name="slot_id" value="<?php echo $slot['id']; ?>" id="slot_<?php echo $slot['id']; ?>" required>
                                    <label for="slot_<?php echo $slot['id']; ?>">
                                        <div class="slot-icon"><?php echo $slot_icon; ?></div>
                                        <div class="slot-details">
                                            <strong>Slot <?php echo htmlspecialchars($slot['slot_number']); ?></strong>
                                            <span><?php echo ucfirst($slot['slot_type']); ?></span>
                                        </div>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-large next-step" data-next="step2">Next: Select Time</button>
                    </div>

                    <div class="form-step" id="step2">
                        <h2>2. Select Time & Duration</h2>
                        <div class="time-selection">
                            <div class="form-group">
                                <label>Start Time</label>
                                <input type="datetime-local" name="start_time" id="start_time" required 
                                       min="<?php echo date('Y-m-d\TH:i'); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Duration</label>
                                <select name="duration" id="duration" required>
                                    <option value="1">1 hour</option>
                                    <option value="2">2 hours</option>
                                    <option value="3">3 hours</option>
                                    <option value="4">4 hours</option>
                                    <option value="6">6 hours</option>
                                    <option value="8">8 hours</option>
                                    <option value="12">12 hours</option>
                                    <option value="24">24 hours</option>
                                </select>
                            </div>
                            
                            <div class="time-preview">
                                <h4>Reservation Summary:</h4>
                                <div class="preview-details">
                                    <div class="preview-item">
                                        <span>Start:</span>
                                        <strong id="preview_start">--</strong>
                                    </div>
                                    <div class="preview-item">
                                        <span>End:</span>
                                        <strong id="preview_end">--</strong>
                                    </div>
                                    <div class="preview-item">
                                        <span>Duration:</span>
                                        <strong id="preview_duration">--</strong>
                                    </div>
                                    <div class="preview-item total">
                                        <span>Total Price:</span>
                                        <strong id="preview_total">--</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="step-actions">
                            <button type="button" class="btn btn-secondary prev-step" data-prev="step1">← Back</button>
                            <button type="button" class="btn btn-large next-step" data-next="step3">Next: Confirm</button>
                        </div>
                    </div>

                    <div class="form-step" id="step3">
                        <h2>3. Confirm Reservation</h2>
                        <div class="confirmation-details">
                            <div class="confirmation-card">
                                <h3>Reservation Details</h3>
                                <div class="detail-row">
                                    <span>Parking Lot:</span>
                                    <strong><?php echo htmlspecialchars($parking_lot['name']); ?></strong>
                                </div>
                                <div class="detail-row">
                                    <span>Address:</span>
                                    <span><?php echo htmlspecialchars($parking_lot['address']); ?></span>
                                </div>
                                <div class="detail-row">
                                    <span>Selected Slot:</span>
                                    <strong id="confirm_slot">--</strong>
                                </div>
                                <div class="detail-row">
                                    <span>Start Time:</span>
                                    <strong id="confirm_start">--</strong>
                                </div>
                                <div class="detail-row">
                                    <span>End Time:</span>
                                    <strong id="confirm_end">--</strong>
                                </div>
                                <div class="detail-row">
                                    <span>Duration:</span>
                                    <strong id="confirm_duration">--</strong>
                                </div>
                                <div class="detail-row total">
                                    <span>Total Amount:</span>
                                    <strong id="confirm_total">--</strong>
                                </div>
                            </div>
                            
                            <div class="payment-method">
                                <h3>Payment Method</h3>
                                <p>Payment will be processed after you complete your parking session.</p>
                                <div class="payment-options">
                                    <div class="payment-option">
                                        <input type="radio" name="payment_method" value="card" id="payment_card" checked>
                                        <label for="payment_card">
                                            <span class="payment-icon">💳</span>
                                            <span>Credit/Debit Card</span>
                                        </label>
                                    </div>
                                    <div class="payment-option">
                                        <input type="radio" name="payment_method" value="wallet" id="payment_wallet">
                                        <label for="payment_wallet">
                                            <span class="payment-icon">👛</span>
                                            <span>Parke Wallet</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="step-actions">
                            <button type="button" class="btn btn-secondary prev-step" data-prev="step2">← Back</button>
                            <button type="submit" name="reserve_slot" class="btn btn-large btn-success">✅ Confirm & Reserve</button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <div class="reservation-summary">
            <div class="summary-card">
                <h3>Parking Lot Info</h3>
                <div class="summary-details">
                    <div class="summary-item">
                        <strong><?php echo htmlspecialchars($parking_lot['name']); ?></strong>
                        <p class="address">📍 <?php echo htmlspecialchars($parking_lot['address']); ?></p>
                    </div>
                    
                    <div class="summary-item">
                        <div class="price-info">
                            <span class="price">UGX <?php echo number_format($parking_lot['price_per_hour'], 2); ?></span>
                            <span class="price-unit">per hour</span>
                        </div>
                    </div>
                    
                    <div class="summary-item">
                        <div class="availability">
                            <span class="available-count"><?php echo $parking_lot['available_slots_count']; ?></span>
                            <span>slots available</span>
                        </div>
                    </div>
                    
                    <?php if ($parking_lot['amenities']): ?>
                    <div class="summary-item">
                        <h4>Amenities:</h4>
                        <div class="amenities-list">
                            <?php
                            $amenities = explode(',', $parking_lot['amenities']);
                            foreach (array_slice($amenities, 0, 3) as $amenity):
                            ?>
                                <span class="amenity-tag"><?php echo htmlspecialchars(trim($amenity)); ?></span>
                            <?php endforeach; ?>
                            <?php if (count($amenities) > 3): ?>
                                <span class="amenity-tag">+<?php echo count($amenities) - 3; ?> more</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function() {
    const steps = document.querySelectorAll('.form-step');
    const nextButtons = document.querySelectorAll('.next-step');
    const prevButtons = document.querySelectorAll('.prev-step');
    const form = document.getElementById('reservationForm');
    
    nextButtons.forEach(button => {
        button.addEventListener('click', function() {
            const currentStep = this.closest('.form-step');
            const nextStepId = this.getAttribute('data-next');
            const nextStep = document.getElementById(nextStepId);
            
            if (validateStep(currentStep.id)) {
                currentStep.classList.remove('active');
                nextStep.classList.add('active');
                updateConfirmation();
            }
        });
    });
    
    prevButtons.forEach(button => {
        button.addEventListener('click', function() {
            const currentStep = this.closest('.form-step');
            const prevStepId = this.getAttribute('data-prev');
            const prevStep = document.getElementById(prevStepId);
            
            currentStep.classList.remove('active');
            prevStep.classList.add('active');
        });
    });

    const durationSelect = document.getElementById('duration');
    const startTimeInput = document.getElementById('start_time');
    
    if (durationSelect && startTimeInput) {
        durationSelect.addEventListener('change', updatePriceAndTime);
        startTimeInput.addEventListener('change', updatePriceAndTime);
    }
    
    const slotInputs = document.querySelectorAll('input[name="slot_id"]');
    slotInputs.forEach(input => {
        input.addEventListener('change', updateConfirmation);
    });
    
    function validateStep(stepId) {
        switch(stepId) {
            case 'step1':
                const selectedSlot = document.querySelector('input[name="slot_id"]:checked');
                if (!selectedSlot) {
                    alert('Please select a parking slot.');
                    return false;
                }
                return true;
                
            case 'step2':
                const startTime = document.getElementById('start_time').value;
                const duration = document.getElementById('duration').value;
                
                if (!startTime) {
                    alert('Please select a start time.');
                    return false;
                }
                
                if (!duration) {
                    alert('Please select a duration.');
                    return false;
                }
                
                return true;
                
            default:
                return true;
        }
    }
    
    function updatePriceAndTime() {
        const startTime = document.getElementById('start_time').value;
        const duration = document.getElementById('duration').value;
        
        if (startTime && duration) {
            const startDate = new Date(startTime);
            const endDate = new Date(startDate.getTime() + (duration * 60 * 60 * 1000));
            
            document.getElementById('preview_start').textContent = formatDateTime(startDate);
            document.getElementById('preview_end').textContent = formatDateTime(endDate);
            document.getElementById('preview_duration').textContent = duration + ' hour' + (duration > 1 ? 's' : '');
            
            const pricePerHour = <?php echo $parking_lot['price_per_hour']; ?>;
            const totalPrice = pricePerHour * duration;
            document.getElementById('preview_total').textContent = 'UGX ' + totalPrice.toFixed(2);
        }
    }
    
    function updateConfirmation() {
        const selectedSlot = document.querySelector('input[name="slot_id"]:checked');
        if (selectedSlot) {
            const slotLabel = selectedSlot.nextElementSibling;
            const slotNumber = slotLabel.querySelector('strong').textContent;
            const slotType = slotLabel.querySelector('span').textContent;
            document.getElementById('confirm_slot').textContent = slotNumber + ' (' + slotType + ')';
        }
        
        const startTime = document.getElementById('start_time').value;
        const duration = document.getElementById('duration').value;
        
        if (startTime && duration) {
            const startDate = new Date(startTime);
            const endDate = new Date(startDate.getTime() + (duration * 60 * 60 * 1000));
            
            document.getElementById('confirm_start').textContent = formatDateTime(startDate);
            document.getElementById('confirm_end').textContent = formatDateTime(endDate);
            document.getElementById('confirm_duration').textContent = duration + ' hour' + (duration > 1 ? 's' : '');
            
            const pricePerHour = <?php echo $parking_lot['price_per_hour']; ?>;
            const totalPrice = pricePerHour * duration;
            document.getElementById('confirm_total').textContent = 'UGX ' + totalPrice.toFixed(2);
        }
    }
    
    function formatDateTime(date) {
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    }
    
    const now = new Date();
    now.setMinutes(now.getMinutes() + 30); 
    document.getElementById('start_time').value = now.toISOString().slice(0, 16);
    
    updatePriceAndTime();
    updateConfirmation();
});
</script>

</body>
</html>