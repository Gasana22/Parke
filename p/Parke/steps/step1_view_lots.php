<?php
// Get filter parameters
$city = isset($_GET['city']) ? $_GET['city'] : '';
$slot_type = isset($_GET['slot_type']) ? $_GET['slot_type'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'available_slots_desc';

// Build query
$query = "SELECT pl.*, 
         (SELECT COUNT(*) FROM parking_slots ps 
          WHERE ps.location_id = pl.id AND ps.status = 'available') as available_count
         FROM parking_locations pl 
         WHERE pl.status = 'active' AND pl.available_slots > 0";

$params = [];
$types = "";

if (!empty($city)) {
    $query .= " AND pl.city = ?";
    $params[] = $city;
    $types .= "s";
}

$query .= " ORDER BY ";
switch($sort) {
    case 'price_asc':
        $query .= "pl.price_per_hour ASC";
        break;
    case 'price_desc':
        $query .= "pl.price_per_hour DESC";
        break;
    case 'available_slots_desc':
        $query .= "available_count DESC";
        break;
    default:
        $query .= "pl.name ASC";
}

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
?>

<h2>Find Available Parking</h2>

<!-- Filter Form -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <input type="hidden" name="step" value="1">
            <div class="col-md-4">
                <label class="form-label">City</label>
                <select name="city" class="form-select">
                    <option value="">All Cities</option>
                    <?php
                    $cities_query = "SELECT DISTINCT city FROM parking_locations ORDER BY city";
                    $cities_result = $conn->query($cities_query);
                    while($city_row = $cities_result->fetch_assoc()) {
                        $selected = ($city == $city_row['city']) ? 'selected' : '';
                        echo "<option value='{$city_row['city']}' $selected>{$city_row['city']}</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Sort By</label>
                <select name="sort" class="form-select">
                    <option value="available_slots_desc" <?php echo $sort == 'available_slots_desc' ? 'selected' : ''; ?>>Most Available</option>
                    <option value="price_asc" <?php echo $sort == 'price_asc' ? 'selected' : ''; ?>>Price: Low to High</option>
                    <option value="price_desc" <?php echo $sort == 'price_desc' ? 'selected' : ''; ?>>Price: High to Low</option>
                    <option value="name_asc" <?php echo $sort == 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Parking Lots Grid -->
<div class="row">
    <?php if ($result->num_rows > 0): ?>
        <?php while($row = $result->fetch_assoc()): ?>
        <div class="col-md-4 mb-4">
            <div class="card parking-card h-100">
                <div class="card-body">
                    <h5 class="card-title"><?php echo htmlspecialchars($row['name']); ?></h5>
                    <p class="card-text">
                        <i class="fas fa-map-marker-alt"></i> 
                        <?php echo htmlspecialchars($row['address']); ?><br>
                        <i class="fas fa-city"></i> <?php echo htmlspecialchars($row['city']); ?>
                    </p>
                    
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <span class="available-slots"><?php echo $row['available_count']; ?></span>
                            <small class="text-muted">slots available</small>
                        </div>
                        <div class="text-end">
                            <h4>$<?php echo number_format($row['price_per_hour'], 2); ?></h4>
                            <small class="text-muted">per hour</small>
                        </div>
                    </div>
                    
                    <a href="?step=2&location_id=<?php echo $row['id']; ?>" 
                       class="btn btn-primary w-100">
                        <i class="fas fa-info-circle"></i> View Details
                    </a>
                </div>
            </div>
        </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="col-12">
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> No parking lots found matching your criteria.
            </div>
        </div>
    <?php endif; ?>
</div>

<?php $stmt->close(); ?>