<?php
$total_cost = isset($_GET['total_cost']) ? $_GET['total_cost'] : 0;

// Get booking details
$query = "SELECT b.*, pl.name as location_name, pl.address, 
         ps.slot_number, ps.slot_type,
         TIMESTAMPDIFF(MINUTE, b.start_time, NOW()) as minutes_parked
         FROM bookings b
         JOIN parking_locations pl ON b.location_id = pl.id
         JOIN parking_slots ps ON b.slot_id = ps.id
         WHERE b.id = ? AND b.user_id = ? AND b.end_time IS NOT NULL";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $booking_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$booking = $result->fetch_assoc();

if (!$booking) {
    echo '<div class="alert alert-danger">Parking session not found.</div>';
    echo '<a href="?step=1" class="btn btn-primary">Back to Parking Lots</a>';
    exit();
}

// Calculate hours (rounded up)
$hours = ceil($booking['minutes_parked'] / 60);
?>

<h2>Payment Confirmation</h2>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h4 class="mb-0"><i class="fas fa-check-circle"></i> Parking Session Completed</h4>
            </div>
            <div class="card-body">
                <div class="text-center mb-4">
                    <div class="display-1 text-success">
                        <i class="fas fa-car"></i>
                    </div>
                    <h3>Thank you for parking with us!</h3>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6>Parking Details</h6>
                        <p class="mb-1"><strong><?php echo htmlspecialchars($booking['location_name']); ?></strong></p>
                        <p class="text-muted small mb-1"><?php echo htmlspecialchars($booking['address']); ?></p>
                        <p class="mb-1">Slot #<?php echo htmlspecialchars($booking['slot_number']); ?></p>
                        <span class="badge bg-info"><?php echo ucfirst($booking['slot_type']); ?></span>
                    </div>
                    <div class="col-md-6">
                        <h6>Timing Information</h6>
                        <p class="mb-1"><strong>Start Time:</strong> <?php echo date('h:i A', strtotime($booking['start_time'])); ?></p>
                        <p class="mb-1"><strong>End Time:</strong> <?php echo date('h:i A', strtotime($booking['end_time'])); ?></p>
                        <p class="mb-1"><strong>Total Duration:</strong> <?php echo floor($booking['minutes_parked']/60); ?>h <?php echo $booking['minutes_parked']%60; ?>m</p>
                        <p class="mb-1"><strong>Billed Hours:</strong> <?php echo $hours; ?> hour(s)</p>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title text-center">Payment Summary</h5>
                        <div class="row">
                            <div class="col-md-6 offset-md-3">
                                <table class="table">
                                    <tr>
                                        <td>Hourly Rate:</td>
                                        <td class="text-end">$<?php echo number_format($booking['total_cost'] / $hours, 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Hours Billed:</td>
                                        <td class="text-end"><?php echo $hours; ?> hour(s)</td>
                                    </tr>
                                    <tr class="table-success">
                                        <td><strong>Total Amount:</strong></td>
                                        <td class="text-end"><strong>$<?php echo number_format($booking['total_cost'], 2); ?></strong></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="alert alert-info">
                    <h6><i class="fas fa-credit-card"></i> Payment Information</h6>
                    <p class="mb-0">
                        The amount will be charged to your registered payment method. 
                        A receipt will be emailed to you.
                    </p>
                </div>
                
                <div class="d-grid gap-2">
                    <form action="actions/process_payment.php" method="POST">
                        <input type="hidden" name="booking_id" value="<?php echo $booking_id; ?>">
                        <button type="submit" class="btn btn-success btn-lg w-100">
                            <i class="fas fa-lock"></i> Confirm Payment of $<?php echo number_format($booking['total_cost'], 2); ?>
                        </button>
                    </form>
                    <a href="?step=1" class="btn btn-outline-primary">
                        <i class="fas fa-parking"></i> Find More Parking
                    </a>
                    <a href="dashboard.php" class="btn btn-outline-secondary">
                        <i class="fas fa-tachometer-alt"></i> Go to Dashboard
                    </a>
                </div>
            </div>
            <div class="card-footer text-center">
                <small class="text-muted">
                    Need help? Contact support@parksmart.com or call 1-800-PARKING
                </small>
            </div>
        </div>
    </div>
</div>

<?php $stmt->close(); ?>