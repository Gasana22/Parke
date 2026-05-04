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
    echo '<div class="alert alert-danger">Booking not found or already completed.</div>';
    echo '<a href="?step=1" class="btn btn-primary">Back to Parking Lots</a>';
    exit();
}
?>

<h2>30-Minute Arrival Window</h2>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card text-center">
            <div class="card-header bg-warning text-dark">
                <h4 class="mb-0"><i class="fas fa-clock"></i> Free Arrival Time</h4>
            </div>
            <div class="card-body">
                <h5 class="card-title">Head to your parking spot</h5>
                <p class="card-text">
                    You have <strong>30 minutes</strong> to arrive at the parking location.<br>
                    Parking charges will only start when you click "Start Parking".
                </p>
                
                <div class="timer-container my-4">
                    <h6>Time Remaining:</h6>
                    <div id="arrival-timer" class="timer">30:00</div>
                    <p class="text-muted small mt-2">Minutes:Seconds</p>
                </div>
                
                <div class="booking-details mb-4">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Location</h6>
                            <p class="mb-1"><strong><?php echo htmlspecialchars($booking['location_name']); ?></strong></p>
                            <p class="text-muted small"><?php echo htmlspecialchars($booking['address']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <h6>Your Spot</h6>
                            <p class="mb-1"><strong>Slot #<?php echo htmlspecialchars($booking['slot_number']); ?></strong></p>
                            <span class="badge bg-info"><?php echo ucfirst($booking['slot_type']); ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="alert alert-info">
                    <h6><i class="fas fa-car"></i> What to do when you arrive:</h6>
                    <ol class="text-start">
                        <li>Park your vehicle in the designated spot</li>
                        <li>Take note of your vehicle's position</li>
                        <li>Click "Start Parking" below to begin the parking timer</li>
                    </ol>
                </div>
                
                <form id="start-parking-form">
                    <input type="hidden" name="booking_id" value="<?php echo $booking_id; ?>">
                    <div class="d-grid gap-2">
                        <button type="submit" id="start-parking-btn" class="btn btn-success btn-lg">
                            <i class="fas fa-play-circle"></i> Start Parking
                        </button>
                        <a href="?step=1" class="btn btn-outline-danger">
                            <i class="fas fa-times"></i> Cancel Reservation
                        </a>
                    </div>
                </form>
            </div>
            <div class="card-footer text-muted">
                <small>If you don't start parking within 30 minutes, your reservation may be cancelled.</small>
            </div>
        </div>
    </div>
</div>

<?php $stmt->close(); ?>