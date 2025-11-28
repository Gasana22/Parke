<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'driver') {
    header("Location: index.php");
    exit();
}

$driver_id = $_SESSION['user_id'];

$create_table_sql = "
CREATE TABLE IF NOT EXISTS favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    parking_lot_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES drivers(id) ON DELETE CASCADE,
    FOREIGN KEY (parking_lot_id) REFERENCES parking_lots(id) ON DELETE CASCADE,
    UNIQUE KEY unique_favorite (user_id, parking_lot_id)
)";
$pdo->exec($create_table_sql);

$favorites_stmt = $pdo->prepare("SELECT pl.*, f.created_at as favorited_date,
                                (SELECT COUNT(*) FROM parking_slots ps WHERE ps.parking_lot_id = pl.id AND ps.is_available = 1) as available_slots_count
                         FROM favorites f 
                         JOIN parking_lots pl ON f.parking_lot_id = pl.id 
                         WHERE f.user_id = ? 
                         ORDER BY f.created_at DESC");
$favorites_stmt->execute([$driver_id]);
$favorites = $favorites_stmt->fetchAll();

if (isset($_POST['remove_favorite'])) {
    $parking_lot_id = $_POST['parking_lot_id'];
    
    $delete_stmt = $pdo->prepare("DELETE FROM favorites WHERE user_id = ? AND parking_lot_id = ?");
    $delete_stmt->execute([$driver_id, $parking_lot_id]);
    
    header("Location: favorites.php?success=removed");
    exit();
}

$recommended_stmt = $pdo->prepare("SELECT pl.*, 
                                  (SELECT COUNT(*) FROM parking_slots ps WHERE ps.parking_lot_id = pl.id AND ps.is_available = 1) as available_slots_count
                           FROM parking_lots pl 
                           WHERE pl.id NOT IN (SELECT parking_lot_id FROM favorites WHERE user_id = ?)
                           AND pl.rating >= 4.0
                           ORDER BY pl.rating DESC, pl.price_per_hour ASC 
                           LIMIT 6");
$recommended_stmt->execute([$driver_id]);
$recommended = $recommended_stmt->fetchAll();

$page_title = "Favorites - Parke";
include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'Parke'; ?></title>

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
    max-width: 1200px;
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
    transition: transform 0.3s ease;
}

.card:hover {
    transform: translateY(-5px);
}

.lot-selector {
    display: flex;
    gap: 1rem;
    align-items: center;
    margin-bottom: 1.5rem;
}

select {
    padding: 12px 16px;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    font-size: 1rem;
    transition: all 0.3s ease;
    background: white;
    cursor: pointer;
}

select:focus {
    border-color: #4299e1;
    outline: none;
    box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
    transform: translateY(-2px);
}

.btn {
    padding: 10px 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    text-decoration: none;
    border-radius: 12px;
    border: none;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 600;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
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

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

.rating-stars {
    color: #FFD700;
    font-size: 1.4rem;
    margin: 0.5rem 0;
    text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
}

.review-card {
    background: white;
    border-radius: 16px;
    padding: 2rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
    border: 2px solid transparent;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.review-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #667eea, #764ba2);
}

.review-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 35px rgba(0,0,0,0.15);
    border-color: #4299e1;
}

.review-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1.5rem;
}

.review-meta {
    flex: 1;
}

.review-meta strong {
    font-size: 1.1rem;
    color: #2d3748;
    font-weight: 700;
}

.review-meta small {
    color: #718096;
    font-size: 0.9rem;
}

.review-rating {
    font-size: 1.8rem;
    color: #FFD700;
    text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
}

.review-content {
    margin-bottom: 1.5rem;
    line-height: 1.7;
    color: #4a5568;
    font-size: 1rem;
}

.response-section {
    background: linear-gradient(135deg, #c6f6d5, #9ae6b4);
    padding: 1.5rem;
    border-radius: 12px;
    margin-top: 1.5rem;
    border: none;
    box-shadow: 0 4px 15px rgba(72, 187, 120, 0.2);
    position: relative;
    overflow: hidden;
}

.response-section::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #48bb78, #38a169);
}

.response-form {
    margin-top: 1.5rem;
    background: linear-gradient(135deg, #bee3f8, #90cdf4);
    padding: 1.5rem;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(66, 153, 225, 0.2);
}

textarea {
    width: 100%;
    padding: 1rem;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    resize: vertical;
    min-height: 100px;
    margin-bottom: 1rem;
    font-size: 1rem;
    transition: all 0.3s ease;
    background: white;
}

textarea:focus {
    border-color: #4299e1;
    outline: none;
    box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
    transform: translateY(-2px);
}

.rating-distribution {
    margin-top: 2rem;
    background: rgba(255, 255, 255, 0.8);
    padding: 1.5rem;
    border-radius: 12px;
    backdrop-filter: blur(10px);
}

.rating-bar {
    display: flex;
    align-items: center;
    margin-bottom: 1rem;
    gap: 1rem;
}

.rating-label {
    width: 80px;
    font-size: 0.9rem;
    font-weight: 600;
    color: #2d3748;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.rating-bar-container {
    flex: 1;
    height: 20px;
    background: #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
}

.rating-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #48bb78, #38a169);
    border-radius: 10px;
    transition: width 0.8s ease;
    position: relative;
    overflow: hidden;
}

.rating-bar-fill::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    transform: translateX(-100%);
}

.rating-bar:hover .rating-bar-fill::after {
    transform: translateX(100%);
    transition: transform 0.6s ease;
}

.rating-count {
    width: 40px;
    text-align: right;
    font-size: 0.9rem;
    font-weight: 600;
    color: #2d3748;
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

.no-reviews {
    text-align: center;
    padding: 4rem 2rem;
    color: #718096;
}

.no-reviews h3 {
    font-size: 1.5rem;
    margin-bottom: 1rem;
    color: #667eea;
    font-weight: 700;
}

.warning {
    background: linear-gradient(135deg, #feebc8, #fbd38d);
    color: #744210;
    padding: 1rem 1.5rem;
    border-radius: 12px;
    margin-bottom: 1.5rem;
    border: none;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

h2 {
    color: #2d3748;
    margin-bottom: 1.5rem;
    font-weight: 700;
    font-size: 1.8rem;
}

h4 {
    color: #2d3748;
    margin-bottom: 1rem;
    font-weight: 600;
    font-size: 1.2rem;
}

@keyframes starPulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

.review-rating:hover {
    animation: starPulse 1s ease-in-out;
}

@media (max-width: 768px) {
    .review-header {
        flex-direction: column;
        gap: 1rem;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .rating-bar {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
    
    .rating-bar-container {
        width: 100%;
    }
    
    .container {
        padding: 0 1rem;
    }
    
    .card {
        padding: 1.5rem;
    }
}
</style>

<div class="container">
    <h1>Your Favorite Parking Lots</h1>
    
    <?php if (isset($_GET['success'])): ?>
        <div class="alert-success">Parking lot removed from favorites!</div>
    <?php endif; ?>
    
    <div class="favorites-layout">
        <div class="favorites-section">
            <h2>❤️ Your Favorites</h2>
            
            <?php if (count($favorites) > 0): ?>
                <div class="favorites-grid">
                    <?php foreach ($favorites as $favorite): ?>
                        <div class="favorite-card">
                            <div class="favorite-header">
                                <h3><?php echo htmlspecialchars($favorite['name']); ?></h3>
                                <form method="POST" class="favorite-form">
                                    <input type="hidden" name="parking_lot_id" value="<?php echo $favorite['id']; ?>">
                                    <button type="submit" name="remove_favorite" class="favorite-btn active" 
                                            title="Remove from favorites">
                                        ❤️
                                    </button>
                                </form>
                            </div>
                            
                            <div class="favorite-details">
                                <p class="address">📍 <?php echo htmlspecialchars($favorite['address']); ?></p>
                                
                                <div class="favorite-stats">
                                    <div class="stat">
                                        <span class="stat-icon">⭐</span>
                                        <span class="stat-value"><?php echo number_format($favorite['rating'], 1); ?></span>
                                    </div>
                                    <div class="stat">
                                        <span class="stat-icon">🅿️</span>
                                        <span class="stat-value"><?php echo $favorite['available_slots_count']; ?> slots</span>
                                    </div>
                                    <div class="stat">
                                        <span class="stat-icon">💰</span>
                                        <span class="stat-value">$<?php echo number_format($favorite['price_per_hour'], 2); ?>/hr</span>
                                    </div>
                                </div>
                                
                                <?php if ($favorite['amenities']): ?>
                                    <div class="amenities">
                                        <strong>🎯 Amenities:</strong> <?php echo htmlspecialchars($favorite['amenities']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="favorited-date">
                                    <small>Added on <?php echo date('M j, Y', strtotime($favorite['favorited_date'])); ?></small>
                                </div>
                            </div>
                            
                            <div class="favorite-actions">
                                <a href="reserve_parking.php?lot_id=<?php echo $favorite['id']; ?>" class="btn">Reserve Now</a>
                                <a href="parking_details.php?lot_id=<?php echo $favorite['id']; ?>" class="btn btn-secondary">View Details</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="favorites-stats">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo count($favorites); ?></div>
                        <div class="stat-label">Total Favorites</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">
                            <?php 
                            $avg_rating = array_sum(array_column($favorites, 'rating')) / count($favorites);
                            echo number_format($avg_rating, 1);
                            ?>
                        </div>
                        <div class="stat-label">Average Rating</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">
                            <?php 
                            $total_slots = array_sum(array_column($favorites, 'available_slots_count'));
                            echo $total_slots;
                            ?>
                        </div>
                        <div class="stat-label">Total Available Slots</div>
                    </div>
                </div>
                
            <?php else: ?>
                <div class="no-favorites">
                    <div class="empty-state">
                        <h3>No favorites yet</h3>
                        <p>Start building your list of favorite parking spots!</p>
                        <p>Click the heart icon ❤️ on any parking lot to add it to your favorites.</p>
                        <a href="find_parking.php" class="btn btn-large">Explore Parking Lots</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="recommended-section">
            <h2>🔥 Recommended For You</h2>
            <p class="section-description">Based on your preferences and highly-rated parking lots</p>
            
            <?php if (count($recommended) > 0): ?>
                <div class="recommended-grid">
                    <?php foreach ($recommended as $lot): ?>
                        <div class="recommended-card">
                            <div class="recommended-header">
                                <h4><?php echo htmlspecialchars($lot['name']); ?></h4>
                                <form method="POST" action="add_favorite.php" class="favorite-form">
                                    <input type="hidden" name="parking_lot_id" value="<?php echo $lot['id']; ?>">
                                    <button type="submit" class="favorite-btn" title="Add to favorites">
                                        🤍
                                    </button>
                                </form>
                            </div>
                            
                            <div class="recommended-details">
                                <p class="address">📍 <?php echo htmlspecialchars($lot['address']); ?></p>
                                
                                <div class="lot-stats">
                                    <span class="rating">⭐ <?php echo number_format($lot['rating'], 1); ?></span>
                                    <span class="slots">🅿️ <?php echo $lot['available_slots_count']; ?> slots</span>
                                    <span class="price">💰 $<?php echo number_format($lot['price_per_hour'], 2); ?>/hr</span>
                                </div>
                            </div>
                            
                            <div class="recommended-actions">
                                <a href="reserve_parking.php?lot_id=<?php echo $lot['id']; ?>" class="btn btn-small">Reserve</a>
                                <a href="parking_details.php?lot_id=<?php echo $lot['id']; ?>" class="btn btn-small btn-secondary">Details</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-recommendations">
                    <p>No recommendations available at the moment.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="quick-actions-section">
            <h2>Quick Actions</h2>
            <div class="quick-actions-grid">
                <a href="find_parking.php" class="quick-action-card">
                    <div class="action-icon">🔍</div>
                    <div class="action-text">
                        <strong>Find Parking</strong>
                        <span>Search for new spots</span>
                    </div>
                </a>
                
                <a href="my_reservations.php" class="quick-action-card">
                    <div class="action-icon">📋</div>
                    <div class="action-text">
                        <strong>My Reservations</strong>
                        <span>View your bookings</span>
                    </div>
                </a>
                
                <?php if (count($favorites) > 0): ?>
                    <a href="find_parking.php?filter=favorites" class="quick-action-card">
                        <div class="action-icon">🚗</div>
                        <div class="action-text">
                            <strong>Park at Favorites</strong>
                            <span>Reserve from your list</span>
                        </div>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</body>
</html>