<?php
// Get location and slot details
$query = "SELECT pl.*, ps.slot_number, ps.slot_type 
         FROM parking_locations pl
         JOIN parking_slots ps ON pl.id = ps.location_id
         WHERE pl.id = ? AND ps.id = ? AND ps.status = 'available'";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $location_id, $slot_id);
$stmt->execute();
$result = $stmt->get_result();
$details = $result->fetch_assoc();

if (!$details) {
    echo '<div class="alert alert-danger">Slot no longer available.</div>';
    echo '<a href="?step=1" class="btn btn-primary">Back to Parking Lots</a>';
    exit();
}
?>

<h2>Confirm Your Parking Reservation</h2>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title text-center mb-4">Booking Summary</h4>
                
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6>Parking Location</h6>
                        <p class="mb-1"><strong><?php echo htmlspecialchars($details['name']); ?></strong></p>
                        <p class="text-muted small mb-1"><?php echo htmlspecialchars($details['address']); ?></p>
                        <p class="text-muted small"><?php echo htmlspecialchars($details['city']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <h6>Selected Slot</h6>
                        <p class="mb-1"><strong>Slot #<?php echo htmlspecialchars($details['slot_number']); ?></strong></p>
                        <span class="badge bg-info"><?php echo ucfirst($details['slot_type']); ?></span>
                    </div>
                </div>
                
                <div class="alert alert-warning">
                    <h6><i class="fas fa-clock"></i> Important Timing Information</h6>
                    <p class="mb-0">
                        After confirming, you'll have <strong>30 minutes</strong> to arrive at the parking spot.<br>
                        Parking charges only start when you click "Start Parking" upon arrival.
                    </p>
                </div>
                
                <div class="card mb-4">
                    <div class="card-body">
                        <h6>Rate Information</h6>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Hourly Rate:</span>
                            <strong>$<?php echo number_format($details['price_per_hour'], 2); ?></strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Minimum Charge:</span>
                            <strong>$<?php echo number_format($details['price_per_hour'], 2); ?> (1 hour)</strong>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between">
                            <span>Estimated cost for 2 hours:</span>
                            <strong>$<?php echo number_format($details['price_per_hour'] * 2, 2); ?></strong>
                        </div>
                    </div>
                </div>
                
                <form action="actions/create_booking.php" method="POST" id="confirm-form">
                    <input type="hidden" name="location_id" value="<?php echo $location_id; ?>">
                    <input type="hidden" name="slot_id" value="<?php echo $slot_id; ?>">
                    
                    <div class="mb-3">
                        <label for="vehicle_number" class="form-label">Vehicle Registration Number</label>
                        <input type="text" class="form-control" id="vehicle_number" 
                               name="vehicle_number" required 
                               placeholder="e.g., ABC-1234" maxlength="20">
                    </div>
                    
                    <div class="alert alert-info">
                        <small>
                            <i class="fas fa-exclamation-triangle"></i>
                            By confirming, you agree to our terms of service. 
                            You will be charged based on actual parking time, 
                            rounded up to the nearest hour.
                        </small>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="fas fa-check-circle"></i> Confirm Parking Reservation
                        </button>
                        <a href="?step=2&location_id=<?php echo $location_id; ?>" 
                           class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Details
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#confirm-form').submit(function(e) {
        e.preventDefault();
        $.ajax({
            url: 'actions/create_booking.php',
            type: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                const result = JSON.parse(response);
                if (result.success) {
                    window.location.href = '?step=4&booking_id=' + result.booking_id;
                } else {
                    alert('Error: ' + result.message);
                }
            }
        });
    });
});
</script>

<?php $stmt->close(); ?>