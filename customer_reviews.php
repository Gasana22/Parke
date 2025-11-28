<?php
session_start(); 
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'operator') {
    header("Location: index.php");
    exit();
}

$operator_id = $_SESSION['user_id'];
$success_message = $error_message = '';

try {
    $check_columns = $pdo->prepare("SHOW COLUMNS FROM reviews LIKE 'operator_response'");
    $check_columns->execute();
    if ($check_columns->rowCount() == 0) {
    
        $pdo->exec("ALTER TABLE reviews ADD COLUMN operator_response TEXT AFTER comment");
        $pdo->exec("ALTER TABLE reviews ADD COLUMN response_date TIMESTAMP NULL AFTER operator_response");
    }
} catch (PDOException $e) {
    $error_message = "Database configuration error: " . $e->getMessage();
}

try {
    $lots_stmt = $pdo->prepare("SELECT id, name FROM parking_lots WHERE operator_id = ? ORDER BY name");
    $lots_stmt->execute([$operator_id]);
    $parking_lots = $lots_stmt->fetchAll();
} catch (PDOException $e) {
    $error_message = "Error loading parking lots: " . $e->getMessage();
    $parking_lots = [];
}

$selected_lot_id = $_GET['lot_id'] ?? 'all';
$reviews = [];
$stats = [
    'total_reviews' => 0,
    'average_rating' => 0,
    'rating_distribution' => [0, 0, 0, 0, 0]
];

if ($selected_lot_id === 'all') {
    try {
        $reviews_stmt = $pdo->prepare("
            SELECT r.*, pl.name as parking_lot_name, u.username as customer_name
            FROM reviews r
            JOIN parking_lots pl ON r.parking_lot_id = pl.id
            JOIN drivers u ON r.user_id = u.id
            WHERE pl.operator_id = ?
            ORDER BY r.created_at DESC
        ");
        $reviews_stmt->execute([$operator_id]);
        $reviews = $reviews_stmt->fetchAll();
        
        $stats_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_reviews,
                AVG(r.rating) as average_rating,
                SUM(CASE WHEN r.rating = 1 THEN 1 ELSE 0 END) as rating_1,
                SUM(CASE WHEN r.rating = 2 THEN 1 ELSE 0 END) as rating_2,
                SUM(CASE WHEN r.rating = 3 THEN 1 ELSE 0 END) as rating_3,
                SUM(CASE WHEN r.rating = 4 THEN 1 ELSE 0 END) as rating_4,
                SUM(CASE WHEN r.rating = 5 THEN 1 ELSE 0 END) as rating_5
            FROM reviews r
            JOIN parking_lots pl ON r.parking_lot_id = pl.id
            WHERE pl.operator_id = ?
        ");
        $stats_stmt->execute([$operator_id]);
        $stats_data = $stats_stmt->fetch();
        
        if ($stats_data) {
            $stats['total_reviews'] = $stats_data['total_reviews'];
            $stats['average_rating'] = round($stats_data['average_rating'], 1);
            $stats['rating_distribution'] = [
                $stats_data['rating_1'],
                $stats_data['rating_2'],
                $stats_data['rating_3'],
                $stats_data['rating_4'],
                $stats_data['rating_5']
            ];
        }
    } catch (PDOException $e) {
        $error_message = "Error loading reviews: " . $e->getMessage();
    }
} else {
    try {
        $reviews_stmt = $pdo->prepare("
            SELECT r.*, pl.name as parking_lot_name, u.username as customer_name
            FROM reviews r
            JOIN parking_lots pl ON r.parking_lot_id = pl.id
            JOIN drivers u ON r.user_id = u.id
            WHERE pl.id = ? AND pl.operator_id = ?
            ORDER BY r.created_at DESC
        ");
        $reviews_stmt->execute([$selected_lot_id, $operator_id]);
        $reviews = $reviews_stmt->fetchAll();
        
        $stats_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_reviews,
                AVG(r.rating) as average_rating,
                SUM(CASE WHEN r.rating = 1 THEN 1 ELSE 0 END) as rating_1,
                SUM(CASE WHEN r.rating = 2 THEN 1 ELSE 0 END) as rating_2,
                SUM(CASE WHEN r.rating = 3 THEN 1 ELSE 0 END) as rating_3,
                SUM(CASE WHEN r.rating = 4 THEN 1 ELSE 0 END) as rating_4,
                SUM(CASE WHEN r.rating = 5 THEN 1 ELSE 0 END) as rating_5
            FROM reviews r
            WHERE r.parking_lot_id = ?
        ");
        $stats_stmt->execute([$selected_lot_id]);
        $stats_data = $stats_stmt->fetch();
        
        if ($stats_data) {
            $stats['total_reviews'] = $stats_data['total_reviews'];
            $stats['average_rating'] = round($stats_data['average_rating'], 1);
            $stats['rating_distribution'] = [
                $stats_data['rating_1'],
                $stats_data['rating_2'],
                $stats_data['rating_3'],
                $stats_data['rating_4'],
                $stats_data['rating_5']
            ];
        }
    } catch (PDOException $e) {
        $error_message = "Error loading reviews: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_response'])) {
    $review_id = $_POST['review_id'];
    $response_text = $_POST['response_text'];
    
    try {
        $response_stmt = $pdo->prepare("UPDATE reviews SET operator_response = ?, response_date = NOW() WHERE id = ?");
        $response_stmt->execute([$response_text, $review_id]);
        $success_message = "Response submitted successfully!";
        header("Location: customer_reviews.php?lot_id=" . $selected_lot_id);
        exit();
    } catch (PDOException $e) {
        $error_message = "Error submitting response: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Customer Reviews - Parke</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }
        body {
            background: #f5f5f5;
            color: #333;
        }
        .header {
            background: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .nav-links {
            display: flex;
            gap: 1rem;
        }
        .nav-links a {
            text-decoration: none;
            color: #333;
            padding: 8px 16px;
            border-radius: 5px;
            transition: background 0.3s;
        }
        .nav-links a:hover {
            background: #f0f0f0;
        }
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        .card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        .lot-selector {
            display: flex;
            gap: 1rem;
            align-items: center;
            margin-bottom: 1rem;
        }
        select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
        }
        .btn {
            padding: 8px 16px;
            background: #2196F3;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #1976D2;
        }
        .btn-success {
            background: #4CAF50;
        }
        .btn-success:hover {
            background: #45a049;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1.5rem 0;
        }
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            border: 1px solid #e0e0e0;
        }
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #2196F3;
        }
        .rating-stars {
            color: #FFD700;
            font-size: 1.2rem;
        }
        .review-card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            background: white;
        }
        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
        }
        .review-meta {
            flex: 1;
        }
        .review-rating {
            font-size: 1.5rem;
            color: #FFD700;
        }
        .review-content {
            margin-bottom: 1rem;
            line-height: 1.6;
        }
        .response-section {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 5px;
            margin-top: 1rem;
            border-left: 4px solid #2196F3;
        }
        .response-form {
            margin-top: 1rem;
        }
        textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            resize: vertical;
            min-height: 80px;
            margin-bottom: 0.5rem;
        }
        .rating-distribution {
            margin-top: 1rem;
        }
        .rating-bar {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        .rating-label {
            width: 80px;
            font-size: 0.9rem;
        }
        .rating-bar-container {
            flex: 1;
            height: 20px;
            background: #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
        }
        .rating-bar-fill {
            height: 100%;
            background: #4CAF50;
        }
        .rating-count {
            width: 40px;
            text-align: right;
            font-size: 0.9rem;
        }
        .message {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
        }
        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .no-reviews {
            text-align: center;
            padding: 2rem;
            color: #666;
        }
        .warning {
            background: #fff3cd;
            color: #856404;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border: 1px solid #ffeaa7;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Customer Reviews & Ratings</h1>
        <div class="nav-links">
            <a href="operator_dashboard.php">← Back to Dashboard</a>
            <a href="manage_parking.php">Manage Parking</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <?php if ($success_message): ?>
            <div class="message success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="message error"><?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <div class="card">
            <h2>Review Statistics</h2>
            <div class="lot-selector">
                <select onchange="location = this.value;">
                    <option value="customer_reviews.php?lot_id=all" <?php echo $selected_lot_id === 'all' ? 'selected' : ''; ?>>All Parking Lots</option>
                    <?php foreach ($parking_lots as $lot): ?>
                        <option value="customer_reviews.php?lot_id=<?php echo $lot['id']; ?>" 
                            <?php echo $selected_lot_id == $lot['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($lot['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <?php if ($stats['total_reviews'] > 0): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_reviews']; ?></div>
                    <div>Total Reviews</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['average_rating']; ?>/5</div>
                    <div class="rating-stars">
                        <?php
                            $full_stars = floor($stats['average_rating']);
                            $half_star = ($stats['average_rating'] - $full_stars) >= 0.5;
                            $empty_stars = 5 - $full_stars - ($half_star ? 1 : 0);
                            
                            echo str_repeat('★', $full_stars);
                            echo $half_star ? '½' : '';
                            echo str_repeat('☆', $empty_stars);
                        ?>
                    </div>
                    <div>Average Rating</div>
                </div>
            </div>
            
            <div class="rating-distribution">
                <h4>Rating Distribution</h4>
                <?php for ($i = 5; $i >= 1; $i--): 
                    $count = $stats['rating_distribution'][$i-1];
                    $percentage = $stats['total_reviews'] > 0 ? ($count / $stats['total_reviews']) * 100 : 0;
                ?>
                <div class="rating-bar">
                    <div class="rating-label"><?php echo $i; ?> ★</div>
                    <div class="rating-bar-container">
                        <div class="rating-bar-fill" style="width: <?php echo $percentage; ?>%"></div>
                    </div>
                    <div class="rating-count"><?php echo $count; ?></div>
                </div>
                <?php endfor; ?>
            </div>
            <?php else: ?>
                <div class="no-reviews">
                    <h3>📝 No Reviews Yet</h3>
                    <p>There are no customer reviews for your parking lots.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($reviews): ?>
        <div class="card">
            <h2>Customer Reviews</h2>
            
            <?php foreach ($reviews as $review): ?>
            <div class="review-card">
                <div class="review-header">
                    <div class="review-meta">
                        <strong><?php echo htmlspecialchars($review['customer_name']); ?></strong>
                        <?php if ($selected_lot_id === 'all'): ?>
                            <br><small>at <?php echo htmlspecialchars($review['parking_lot_name']); ?></small>
                        <?php endif; ?>
                        <br><small><?php echo date('M j, Y g:i A', strtotime($review['created_at'])); ?></small>
                    </div>
                    <div class="review-rating">
                        <?php echo str_repeat('★', $review['rating']); ?><?php echo str_repeat('☆', 5 - $review['rating']); ?>
                    </div>
                </div>
                
                <div class="review-content">
                    <?php echo nl2br(htmlspecialchars($review['comment'])); ?>
                </div>
                
                <?php if (!empty($review['operator_response'])): ?>
                    <div class="response-section">
                        <strong>Your Response:</strong>
                        <p><?php echo nl2br(htmlspecialchars($review['operator_response'])); ?></p>
                        <small>Responded on <?php echo date('M j, Y g:i A', strtotime($review['response_date'])); ?></small>
                    </div>
                <?php else: ?>
                    <div class="response-form">
                        <form method="POST" action="">
                            <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                            <textarea name="response_text" placeholder="Write a response to this review..." required></textarea>
                            <button type="submit" name="submit_response" class="btn btn-success">Submit Response</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php elseif ($stats['total_reviews'] > 0): ?>
            <div class="card">
                <div class="no-reviews">
                    <h3>No Reviews for Selected Lot</h3>
                    <p>There are no reviews for the selected parking lot.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>