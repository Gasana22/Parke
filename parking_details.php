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
                      (SELECT COUNT(*) FROM parking_slots ps WHERE ps.parking_lot_id = pl.id AND ps.is_available = 1) as available_slots_count,
                      (SELECT COUNT(*) FROM reviews r WHERE r.parking_lot_id = pl.id) as total_reviews,
                      (SELECT AVG(rating) FROM reviews r WHERE r.parking_lot_id = pl.id) as avg_rating
               FROM parking_lots pl 
               WHERE pl.id = ?");
$stmt->execute([$parking_lot_id]);
$parking_lot = $stmt->fetch();

if (!$parking_lot) {
    header("Location: find_parking.php");
    exit();
}

$images_stmt = $pdo->prepare("SELECT * FROM parking_lot_images WHERE parking_lot_id = ? ORDER BY is_primary DESC, uploaded_at DESC");
$images_stmt->execute([$parking_lot_id]);
$parking_images = $images_stmt->fetchAll();

if (count($parking_images) === 0) {
    $parking_images = [
        ['image_path' => 'default_parking.jpg', 'is_primary' => true]
    ];
}

$slots_stmt = $pdo->prepare("SELECT * FROM parking_slots WHERE parking_lot_id = ? AND is_available = 1 ORDER BY slot_number");
$slots_stmt->execute([$parking_lot_id]);
$available_slots = $slots_stmt->fetchAll();

$reviews_stmt = $pdo->prepare("SELECT r.*, d.username 
                              FROM reviews r 
                              JOIN drivers d ON r.user_id = d.id 
                              WHERE r.parking_lot_id = ? 
                              ORDER BY r.created_at DESC 
                              LIMIT 5");
$reviews_stmt->execute([$parking_lot_id]);
$recent_reviews = $reviews_stmt->fetchAll();

$favorite_stmt = $pdo->prepare("SELECT id FROM favorites WHERE user_id = ? AND parking_lot_id = ?");
$favorite_stmt->execute([$driver_id, $parking_lot_id]);
$is_favorited = $favorite_stmt->fetch();

if (isset($_POST['toggle_favorite'])) {
    if ($is_favorited) {
        $delete_stmt = $pdo->prepare("DELETE FROM favorites WHERE user_id = ? AND parking_lot_id = ?");
        $delete_stmt->execute([$driver_id, $parking_lot_id]);
    } else {
        $insert_stmt = $pdo->prepare("INSERT INTO favorites (user_id, parking_lot_id) VALUES (?, ?)");
        $insert_stmt->execute([$driver_id, $parking_lot_id]);
    }
    header("Location: parking_details.php?lot_id=" . $parking_lot_id);
    exit();
}

$page_title = $parking_lot['name'] . " - Parke";
include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'Parke'; ?></title>

<style>
.parking-details-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 1rem;
}

.breadcrumb {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 2rem;
    padding: 1rem 1.5rem;
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(10px);
    border-radius: 12px;
    box-shadow: var(--shadow);
    font-size: 0.9rem;
}

.breadcrumb a {
    color: #4361ee;
    text-decoration: none;
    font-weight: 600;
    transition: var(--transition);
}

.breadcrumb a:hover {
    color: #7209b7;
}

.parking-header {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 2.5rem;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    margin-bottom: 2rem;
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 2rem;
    align-items: start;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.header-main h1 {
    font-size: 2.5rem;
    font-weight: 800;
    color: #2d3748;
    margin-bottom: 1rem;
    background: linear-gradient(135deg, #2d3748, #4a5568);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.rating-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background: linear-gradient(135deg, #ffd700, #ffed4e);
    color: #2d3748;
    padding: 0.75rem 1.5rem;
    border-radius: 25px;
    font-weight: 700;
    box-shadow: 0 4px 15px rgba(255, 215, 0, 0.3);
}

.review-count {
    font-size: 0.9rem;
    font-weight: 600;
    opacity: 0.8;
}

.header-actions {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    align-items: flex-end;
}

.favorite-form {
    margin: 0;
}

.btn-favorite {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 1rem 1.5rem;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    background: white;
    color: #4a5568;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
}

.btn-favorite-active {
    background: linear-gradient(135deg, #f72585, #e53e3e);
    color: white;
    border-color: transparent;
}

.btn-favorite:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow);
}

.btn-reserve {
    background: linear-gradient(135deg, #4361ee, #7209b7);
    color: white;
    padding: 1.25rem 2rem;
    border-radius: 12px;
    text-decoration: none;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    transition: var(--transition);
}

.btn-reserve:hover {
    transform: translateY(-3px);
    box-shadow: 0 15px 30px rgba(67, 97, 238, 0.3);
}

.details-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 2rem;
}

.detail-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 2rem;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    margin-bottom: 1.5rem;
    border: 1px solid rgba(255, 255, 255, 0.2);
    transition: var(--transition);
}

.detail-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-hover);
}

.detail-card h2 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #2d3748;
    margin-bottom: 1.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 3px solid;
    border-image: linear-gradient(135deg, #4361ee, #7209b7) 1;
}

.image-gallery-preview {
    position: relative;
}

.main-preview-image {
    width: 100%;
    height: 400px;
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 1rem;
    position: relative;
}

.main-preview-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}

.main-preview-image:hover img {
    transform: scale(1.05);
}

.no-image-large {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    color: #a0aec0;
    font-size: 4rem;
}

.no-image-large span {
    font-size: 1.1rem;
    margin-top: 1rem;
    color: #718096;
}

.preview-thumbnails {
    display: flex;
    gap: 0.75rem;
    margin-bottom: 1.5rem;
    overflow-x: auto;
    padding: 0.5rem 0;
}

.preview-thumbnail {
    width: 100px;
    height: 75px;
    object-fit: cover;
    border: 3px solid transparent;
    border-radius: 8px;
    cursor: pointer;
    transition: var(--transition);
    flex-shrink: 0;
}

.preview-thumbnail:hover,
.preview-thumbnail.active {
    border-color: #4361ee;
    transform: scale(1.05);
}

.gallery-actions {
    text-align: center;
}

.overview-grid {
    display: grid;
    gap: 1.5rem;
}

.overview-item {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    padding: 1.5rem;
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    border-radius: 12px;
    transition: var(--transition);
}

.overview-item:hover {
    transform: translateX(10px);
    background: linear-gradient(135deg, #edf2f7, #e2e8f0);
}

.overview-icon {
    font-size: 2.5rem;
    flex-shrink: 0;
}

.overview-text {
    display: flex;
    flex-direction: column;
}

.overview-text strong {
    font-size: 1.3rem;
    font-weight: 700;
    color: #2d3748;
    margin-bottom: 0.25rem;
}

.overview-text span {
    color: #718096;
    font-size: 0.9rem;
}

.amenities-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.amenity-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem 1.25rem;
    background: linear-gradient(135deg, #e8f5e8, #d4edda);
    border-radius: 10px;
    transition: var(--transition);
}

.amenity-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(72, 187, 120, 0.2);
}

.amenity-icon {
    font-size: 1.5rem;
}

.slots-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 1rem;
}

.slot-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.25rem;
    background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
    border-radius: 12px;
    border-left: 4px solid #4361ee;
    transition: var(--transition);
}

.slot-item:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(67, 97, 238, 0.15);
}

.slot-icon {
    font-size: 1.75rem;
}

.slot-info {
    display: flex;
    flex-direction: column;
}

.slot-info strong {
    color: #2d3748;
    font-weight: 700;
}

.slot-info span {
    color: #718096;
    font-size: 0.85rem;
    margin-top: 0.25rem;
}

.address {
    font-size: 1.2rem;
    color: #4a5568;
    margin-bottom: 1.5rem;
    padding: 1rem 1.5rem;
    background: #f7fafc;
    border-radius: 10px;
    border-left: 4px solid #4361ee;
}

.map-placeholder {
    text-align: center;
    padding: 3rem 2rem;
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    border-radius: 12px;
    border: 2px dashed #cbd5e0;
    transition: var(--transition);
}

.map-placeholder:hover {
    border-color: #4361ee;
    background: linear-gradient(135deg, #edf2f7, #e2e8f0);
}

.map-image {
    font-size: 4rem;
    margin-bottom: 1rem;
    opacity: 0.7;
}

.no-slots, .no-reviews {
    text-align: center;
    color: #a0aec0;
    font-style: italic;
    padding: 3rem 2rem;
    background: #f7fafc;
    border-radius: 12px;
    border: 2px dashed #e2e8f0;
}

@media (max-width: 1024px) {
    .details-grid {
        grid-template-columns: 1fr;
    }
    
    .parking-header {
        grid-template-columns: 1fr;
        text-align: center;
    }
    
    .header-actions {
        align-items: center;
        flex-direction: row;
        justify-content: center;
    }
}

@media (max-width: 768px) {
    .parking-header {
        padding: 1.5rem;
    }
    
    .header-main h1 {
        font-size: 2rem;
    }
    
    .header-actions {
        flex-direction: column;
    }
    
    .amenities-grid {
        grid-template-columns: 1fr;
    }
    
    .slots-grid {
        grid-template-columns: 1fr;
    }
    
    .overview-item {
        flex-direction: column;
        text-align: center;
        gap: 1rem;
    }
}
</style>

<div class="container">
    <div class="breadcrumb">
        <a href="find_parking.php">Find Parking</a> &gt; 
        <span><?php echo htmlspecialchars($parking_lot['name']); ?></span>
    </div>

    <div class="parking-header-details">
        <div class="header-main">
            <h1><?php echo htmlspecialchars($parking_lot['name']); ?></h1>
            <div class="rating-badge">
                ⭐ <?php echo number_format($parking_lot['avg_rating'], 1); ?> 
                <span class="review-count">(<?php echo $parking_lot['total_reviews']; ?> reviews)</span>
            </div>
        </div>
        
        <div class="header-actions">
            <form method="POST" class="favorite-form">
                <button type="submit" name="toggle_favorite" class="btn <?php echo $is_favorited ? 'btn-favorite-active' : 'btn-favorite'; ?>">
                    <?php echo $is_favorited ? '❤️ Remove from Favorites' : '🤍 Add to Favorites'; ?>
                </button>
            </form>
            <a href="reserve_parking.php?lot_id=<?php echo $parking_lot_id; ?>" class="btn btn-large">
                🅿️ Reserve Now
            </a>
        </div>
    </div>
    <div class="details-grid">
        <div class="details-left">
            <div class="detail-card">
                <h2>📷 Parking Lot Images</h2>
                <div class="image-gallery-preview">
                    <div class="main-preview-image">
                        <?php if (!empty($parking_images[0]['image_path'])): ?>
                            <img src="<?php echo htmlspecialchars($parking_images[0]['image_path']); ?>" 
                                 alt="<?php echo htmlspecialchars($parking_lot['name']); ?>" 
                                 id="mainPreviewImage"
                                 onerror="this.src='default_parking.jpg'">
                        <?php else: ?>
                            <div class="no-image-large">
                                🅿️
                                <span>No Image Available</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (count($parking_images) > 1): ?>
                    <div class="preview-thumbnails">
                        <?php foreach ($parking_images as $index => $image): ?>
                            <img src="<?php echo htmlspecialchars($image['image_path']); ?>" 
                                 alt="Parking image <?php echo $index + 1; ?>"
                                 class="preview-thumbnail <?php echo $index === 0 ? 'active' : ''; ?>"
                                 onclick="changePreviewImage('<?php echo htmlspecialchars($image['image_path']); ?>', this)"
                                 onerror="this.src='default_parking.jpg'">
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="gallery-actions">
                        <button class="btn btn-secondary" onclick="openImageGallery()">
                            📷 View All Images (<?php echo count($parking_images); ?>)
                        </button>
                    </div>
                </div>
            </div>

            <div class="detail-card">
                <h2>📍 Overview</h2>
                <div class="overview-grid">
                    <div class="overview-item">
                        <div class="overview-icon">🅿️</div>
                        <div class="overview-text">
                            <strong><?php echo $parking_lot['available_slots_count']; ?> Slots Available</strong>
                            <span>Out of <?php echo $parking_lot['total_slots']; ?> total</span>
                        </div>
                    </div>
                    <div class="overview-item">
                        <div class="overview-icon">💰</div>
                        <div class="overview-text">
                            <strong>UGX <?php echo number_format($parking_lot['price_per_hour'], 2); ?>/hour</strong>
                            <span>Parking rate</span>
                        </div>
                    </div>
                    <div class="overview-item">
                        <div class="overview-icon">⭐</div>
                        <div class="overview-text">
                            <strong><?php echo number_format($parking_lot['avg_rating'], 1); ?> Rating</strong>
                            <span>Based on <?php echo $parking_lot['total_reviews']; ?> reviews</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="detail-card">
                <h2>🏢 Location</h2>
                <p class="address"><?php echo htmlspecialchars($parking_lot['address']); ?></p>
                <?php if ($parking_lot['latitude'] && $parking_lot['longitude']): ?>
                    <div class="map-placeholder">
                        <div class="map-image">
                            🗺️
                        </div>
                        <p><small>Interactive map would be displayed here</small></p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($parking_lot['amenities']): ?>
            <div class="detail-card">
                <h2>🎯 Amenities</h2>
                <div class="amenities-grid">
                    <?php
                    $amenities = explode(',', $parking_lot['amenities']);
                    foreach ($amenities as $amenity):
                        $amenity = trim($amenity);
                        $icons = [
                            'security' => '🔒',
                            'cctv' => '📹',
                            'covered' => '🏠',
                            'lighting' => '💡',
                            'disabled access' => '♿'
                        ];
                        $icon = $icons[strtolower($amenity)] ?? '✅';
                    ?>
                        <div class="amenity-item">
                            <span class="amenity-icon"><?php echo $icon; ?></span>
                            <span class="amenity-text"><?php echo htmlspecialchars($amenity); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="detail-card">
                <h2>🅿️ Available Slots</h2>
                <?php if (count($available_slots) > 0): ?>
                    <div class="slots-grid">
                        <?php foreach ($available_slots as $slot): 
                            $slot_icons = [
                                'standard' => '🚗',
                                'disabled' => '♿',
                                'ev_charging' => '⚡',
                                'large' => '🚙'
                            ];
                            $slot_icon = $slot_icons[$slot['slot_type']] ?? '🚗';
                        ?>
                            <div class="slot-item" data-slot-type="<?php echo $slot['slot_type']; ?>">
                                <div class="slot-icon"><?php echo $slot_icon; ?></div>
                                <div class="slot-info">
                                    <strong>Slot <?php echo htmlspecialchars($slot['slot_number']); ?></strong>
                                    <span><?php echo ucfirst($slot['slot_type']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="no-slots">No available slots at the moment. Please check back later.</p>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script>
let currentGalleryIndex = 0;
const parkingImages = <?php echo json_encode(array_column($parking_images, 'image_path')); ?>;

function changePreviewImage(imageSrc, element) {
    document.getElementById('mainPreviewImage').src = imageSrc;
    
    document.querySelectorAll('.preview-thumbnail').forEach(thumb => {
        thumb.classList.remove('active');
    });
    element.classList.add('active');
}

function openImageGallery() {
    const modal = document.getElementById('fullImageGallery');
    currentGalleryIndex = 0;
    updateGalleryImage();
    modal.style.display = 'flex';
}

function updateGalleryImage() {
    if (parkingImages.length > 0) {
        document.getElementById('fullGalleryImage').src = parkingImages[currentGalleryIndex];
        document.getElementById('galleryCounter').textContent = `${currentGalleryIndex + 1} / ${parkingImages.length}`;
        document.querySelectorAll('.gallery-thumbnail').forEach((thumb, index) => {
            thumb.classList.toggle('active', index === currentGalleryIndex);
        });
    }
}

function changeGalleryImage(direction) {
    currentGalleryIndex += direction;
    
    if (currentGalleryIndex < 0) {
        currentGalleryIndex = parkingImages.length - 1;
    } else if (currentGalleryIndex >= parkingImages.length) {
        currentGalleryIndex = 0;
    }
    
    updateGalleryImage();
}

function setGalleryImage(index) {
    currentGalleryIndex = index;
    updateGalleryImage();
}

document.querySelectorAll('.close-modal').forEach(button => {
    button.addEventListener('click', function() {
        document.getElementById('fullImageGallery').style.display = 'none';
    });
});

document.getElementById('fullImageGallery').addEventListener('click', function(e) {
    if (e.target === this) {
        this.style.display = 'none';
    }
});

document.addEventListener('keydown', function(e) {
    const gallery = document.getElementById('fullImageGallery');
    if (gallery.style.display === 'flex') {
        if (e.key === 'Escape') {
            gallery.style.display = 'none';
        } else if (e.key === 'ArrowLeft') {
            changeGalleryImage(-1);
        } else if (e.key === 'ArrowRight') {
            changeGalleryImage(1);
        }
    }
});
</script>

</body>
</html>