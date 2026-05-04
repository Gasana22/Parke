<?php
// Get booking details
$query = "SELECT b.*, pl.name as location_name, pl.address, pl.price_per_hour, 
         ps.slot_number, ps.slot_type
         FROM bookings b
         JOIN parking_locations pl ON b.location_id = pl.id
         JOIN parking_slots ps ON b.slot_id = ps.id
         WHERE b.id = ? AND b.user_id = ? AND b.end_time IS NULL";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $booking_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$booking = $result->fetch_assoc();

if (!$booking) {
    echo '<div class="alert alert-danger">Parking session not found or already completed.</div>';
    echo '<a href="?step=1" class="btn btn-primary">Back to Parking Lots</a>';
    exit();
}

// Update slot status to occupied
$update_slot = $conn->prepare("UPDATE parking_slots SET status = 'occupied', current_vehicle = ? WHERE id = ?");
$update_slot->bind_param("si", $booking['vehicle_number'], $booking['slot_id']);
$update_slot->execute();
$update_slot->close();
?>

<h2>Parking in Progress</h2>

<div class="row justify-content-center">
    <div class="col-md-10">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0"><i class="fas fa-car"></i> Active Parking Session</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <h5 class="card-title"><?php echo htmlspecialchars($booking['location_name']); ?></h5>
                        <p class="card-text">
                            <i class="fas fa-map-marker-alt"></i> 
                            <?php echo htmlspecialchars($booking['address']); ?><br>
                            <i class="fas fa-hashtag"></i> 
                            Slot #<?php echo htmlspecialchars($booking['slot_number']); ?> 
                            <span class="badge bg-info"><?php echo ucfirst($booking['slot_type']); ?></span>
                        </p>
                        
                        <div class="alert alert-success">
                            <h6><i class="fas fa-check-circle"></i> Parking Started</h6>
                            <p class="mb-0">Your parking session is now active. Timer started at: 
                                <strong><?php echo date('h:i:s A'); ?></strong></p>
                        </div>
                        
                        <div class="timer-container text-center my-4">
                            <h6>Parking Duration:</h6>
                            <div id="parking-timer" class="timer">00:00:00</div>
                            <p class="text-muted">Hours:Minutes:Seconds</p>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title">Pricing Details</h6>
                                <div class="mb-3">
                                    <small class="text-muted">Hourly Rate</small>
                                    <h4>$<?php echo number_format($booking['price_per_hour'], 2); ?></h4>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-muted">Current Estimated Cost</small>
                                    <h3 id="current-cost">$0.00 (est.)</h3>
                                </div>
                                
                                <div class="alert alert-warning small">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <strong>Note:</strong> Time is rounded UP to the nearest hour. 
                                    Minimum charge: 1 hour.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="alert alert-info mt-4">
                    <h6><i class="fas fa-info-circle"></i> Important Information</h6>
                    <ul class="mb-0">
                        <li>Parking charges are calculated in real-time</li>
                        <li>Click "End Parking" when you return to your vehicle</li>
                        <li>Your final cost will be calculated and displayed</li>
                        <li>Payment will be processed after ending parking</li>
                    </ul>
                </div>
                
                <form id="end-parking-form">
                    <input type="hidden" name="booking_id" value="<?php echo $booking_id; ?>">
                    <div class="d-grid">
                        <button type="submit" class="btn btn-danger btn-lg">
                            <i class="fas fa-stop-circle"></i> End Parking Session
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php $stmt->close(); ?>