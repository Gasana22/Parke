<?php
// Get location details
$query = "SELECT pl.*, 
         (SELECT COUNT(*) FROM parking_slots ps 
          WHERE ps.location_id = pl.id AND ps.status = 'available') as available_count,
         u.username as admin_name
         FROM parking_locations pl
         LEFT JOIN users u ON pl.admin_id = u.id
         WHERE pl.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $location_id);
$stmt->execute();
$result = $stmt->get_result();
$location = $result->fetch_assoc();

if (!$location) {
    echo '<div class="alert alert-danger">Location not found.</div>';
    exit();
}

// Get available slots
$slots_query = "SELECT * FROM parking_slots 
               WHERE location_id = ? AND status = 'available' 
               ORDER BY slot_type, slot_number";
$slots_stmt = $conn->prepare($slots_query);
$slots_stmt->bind_param("i", $location_id);
$slots_stmt->execute();
$slots_result = $slots_stmt->get_result();
?>

<h2><?php echo htmlspecialchars($location['name']); ?></h2>

<div class="row">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Location Details</h5>
                <p><strong>Address:</strong> <?php echo htmlspecialchars($location['address']); ?></p>
                <p><strong>City:</strong> <?php echo htmlspecialchars($location['city']); ?></p>
                <p><strong>Total Slots:</strong> <?php echo $location['total_slots']; ?></p>
                <p><strong>Available Now:</strong> <span class="text-success fw-bold"><?php echo $location['available_count']; ?></span></p>
                <p><strong>Hourly Rate:</strong> <span class="text-primary fw-bold">$<?php echo number_format($location['price_per_hour'], 2); ?></span></p>
                <?php if ($location['admin_name']): ?>
                <p><strong>Managed by:</strong> <?php echo htmlspecialchars($location['admin_name']); ?></p>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Available Slots</h5>
                <div class="row">
                    <?php if ($slots_result->num_rows > 0): ?>
                        <?php while($slot = $slots_result->fetch_assoc()): ?>
                        <div class="col-md-4 mb-3">
                            <div class="card <?php echo $slot['status'] == 'available' ? 'border-success' : 'border-secondary'; ?>">
                                <div class="card-body text-center">
                                    <h6>Slot #<?php echo htmlspecialchars($slot['slot_number']); ?></h6>
                                    <span class="badge bg-<?php 
                                        switch($slot['slot_type']) {
                                            case 'disabled': echo 'warning'; break;
                                            case 'electric': echo 'info'; break;
                                            default: echo 'success';
                                        }
                                    ?>">
                                        <?php echo ucfirst($slot['slot_type']); ?>
                                    </span>
                                    <p class="mt-2 mb-0">
                                        <strong>Status:</strong> 
                                        <span class="text-<?php echo $slot['status'] == 'available' ? 'success' : 'danger'; ?>">
                                            <?php echo ucfirst($slot['status']); ?>
                                        </span>
                                    </p>
                                    <?php if ($slot['status'] == 'available'): ?>
                                    <a href="?step=3&location_id=<?php echo $location_id; ?>&slot_id=<?php echo $slot['id']; ?>" 
                                       class="btn btn-sm btn-primary mt-2 w-100">
                                        Select This Slot
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="alert alert-warning">
                                No available slots at this time. Please check back later.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Pricing Information</h5>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between">
                        <span>First hour:</span>
                        <strong>$<?php echo number_format($location['price_per_hour'], 2); ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span>Additional hours:</span>
                        <strong>$<?php echo number_format($location['price_per_hour'], 2); ?>/hour</strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span>Daily maximum:</span>
                        <strong>$<?php echo number_format($location['price_per_hour'] * 24, 2); ?></strong>
                    </li>
                </ul>
                <div class="alert alert-info mt-3">
                    <small>
                        <i class="fas fa-info-circle"></i> 
                        <strong>Note:</strong> Time is rounded UP to the nearest hour. 
                        You have 30 minutes to arrive after confirming your booking.
                    </small>
                </div>
                
                <a href="?step=1" class="btn btn-outline-secondary w-100 mt-3">
                    <i class="fas fa-arrow-left"></i> Back to Parking Lots
                </a>
            </div>
        </div>
    </div>
</div>

<?php 
$stmt->close();
$slots_stmt->close();
?>