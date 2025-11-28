<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'driver') {
    header("Location: index.php");
    exit();
}

$driver_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT r.*, pl.name as parking_lot_name, pl.id as parking_lot_id, pl.address,
                              (SELECT COUNT(*) FROM reviews rev WHERE rev.parking_lot_id = pl.id) as total_reviews,
                              (SELECT AVG(rating) FROM reviews rev WHERE rev.parking_lot_id = pl.id) as avg_rating
                       FROM reservations r 
                       JOIN parking_slots ps ON r.parking_slot_id = ps.id 
                       JOIN parking_lots pl ON ps.parking_lot_id = pl.id 
                       WHERE r.user_id = ? AND r.status = 'completed' 
                       AND pl.id NOT IN (SELECT parking_lot_id FROM reviews WHERE user_id = ?)
                       ORDER BY r.end_time DESC");
$stmt->execute([$driver_id, $driver_id]);
$reservations = $stmt->fetchAll();

$previous_reviews_stmt = $pdo->prepare("SELECT r.*, pl.name as parking_lot_name 
                                       FROM reviews r 
                                       JOIN parking_lots pl ON r.parking_lot_id = pl.id 
                                       WHERE r.user_id = ? 
                                       ORDER BY r.created_at DESC");
$previous_reviews_stmt->execute([$driver_id]);
$previous_reviews = $previous_reviews_stmt->fetchAll();

if (isset($_POST['submit_review'])) {
    $parking_lot_id = $_POST['parking_lot_id'];
    $rating = $_POST['rating'];
    $comment = $_POST['comment'];
    
    $stmt = $pdo->prepare("INSERT INTO reviews (user_id, parking_lot_id, rating, comment) VALUES (?, ?, ?, ?)");
    $stmt->execute([$driver_id, $parking_lot_id, $rating, $comment]);
    
    $update_rating_stmt = $pdo->prepare("UPDATE parking_lots SET rating = (
        SELECT AVG(rating) FROM reviews WHERE parking_lot_id = ?
    ) WHERE id = ?");
    $update_rating_stmt->execute([$parking_lot_id, $parking_lot_id]);
    
    $notification_stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'Review Submitted', 'Thank you for your review!')");
    $notification_stmt->execute([$driver_id]);
    
    header("Location: review.php?success=1");
    exit();
}

if (isset($_POST['edit_review'])) {
    $review_id = $_POST['review_id'];
    $rating = $_POST['rating'];
    $comment = $_POST['comment'];
    
    $stmt = $pdo->prepare("UPDATE reviews SET rating = ?, comment = ? WHERE id = ? AND user_id = ?");
    $stmt->execute([$rating, $comment, $review_id, $driver_id]);
    
    header("Location: review.php?success=edited");
    exit();
}

$page_title = "Write Review - Parke";
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
        max-width: 1000px;
        margin: 2rem auto;
        padding: 0 2rem;
    }

    h1 {
        font-size: 2.5rem;
        font-weight: 800;
        margin-bottom: 2rem;
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 1rem;
    }

    h1::before {
        content: '⭐';
        font-size: 2.5rem;
    }

    h2 {
        font-size: 1.8rem;
        font-weight: 700;
        margin-bottom: 1rem;
        color: var(--dark);
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .alert-success {
        background: linear-gradient(135deg, var(--secondary), #0da271);
        color: white;
        padding: 1.5rem;
        border-radius: 12px;
        margin-bottom: 2rem;
        box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        border-left: 4px solid white;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .review-layout {
        display: grid;
        gap: 2rem;
    }

    .review-section {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        padding: 2.5rem;
        border-radius: 20px;
        box-shadow: var(--shadow);
        border: 1px solid rgba(255, 255, 255, 0.2);
        transition: var(--transition);
    }

    .review-section:hover {
        box-shadow: 0 12px 30px rgba(0,0,0,0.15);
    }

    .section-description {
        color: var(--gray);
        margin-bottom: 1.5rem;
        font-size: 1.1rem;
        line-height: 1.6;
    }

    .review-card {
        background: white;
        border-radius: 16px;
        padding: 2rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        transition: var(--transition);
        position: relative;
        overflow: hidden;
        border: 1px solid #f1f5f9;
    }

    .review-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 6px;
        height: 100%;
        transition: var(--transition);
    }

    .review-card.pending {
        border-left: 6px solid var(--warning);
        background: linear-gradient(135deg, #fffaf0, #ffffff);
    }

    .review-card.pending::before {
        background: var(--warning);
    }

    .review-card.previous {
        border-left: 6px solid var(--secondary);
    }

    .review-card.previous::before {
        background: var(--secondary);
    }

    .review-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.12);
    }

    .review-header {
        margin-bottom: 1.5rem;
    }

    .review-header h3 {
        margin-bottom: 0.75rem;
        color: var(--dark);
        font-size: 1.4rem;
        font-weight: 700;
    }

    .parking-info, .review-meta {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .address, .visit-date, .review-date {
        color: var(--gray);
        font-size: 0.95rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .address::before { content: '📍'; }
    .visit-date::before { content: '📅'; }
    .review-date::before { content: '🕒'; }

    .lot-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
        padding: 1.5rem;
        background: rgba(99, 102, 241, 0.05);
        border-radius: 12px;
        border: 1px solid rgba(99, 102, 241, 0.1);
    }

    .stat {
        text-align: center;
    }

    .stat-value {
        display: block;
        font-size: 2rem;
        font-weight: 800;
        color: var(--primary);
        margin-bottom: 0.25rem;
    }

    .stat-label {
        font-size: 0.85rem;
        color: var(--gray);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
    }

    .rating-section {
        margin-bottom: 1.5rem;
    }

    .rating-section label {
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 1rem;
        display: block;
    }

    .star-rating {
        display: flex;
        flex-direction: row-reverse;
        justify-content: flex-start;
        gap: 0.5rem;
        margin-top: 0.5rem;
    }

    .star-rating input {
        display: none;
    }

    .star-rating label {
        font-size: 2.5rem;
        cursor: pointer;
        transition: var(--transition);
        color: #e2e8f0;
        margin: 0;
    }

    .star-rating input:checked ~ label,
    .star-rating label:hover,
    .star-rating label:hover ~ label {
        color: #ffd700;
        transform: scale(1.1);
    }

    .comment-section {
        margin-bottom: 2rem;
    }

    .comment-section label {
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 0.75rem;
        display: block;
    }

    .comment-section textarea {
        width: 100%;
        padding: 1.25rem;
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        font-size: 1rem;
        resize: vertical;
        transition: var(--transition);
        font-family: inherit;
        line-height: 1.5;
    }

    .comment-section textarea:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
    }

    .char-count {
        text-align: right;
        font-size: 0.85rem;
        color: var(--gray);
        margin-top: 0.5rem;
        font-weight: 500;
    }

    .rating-display {
        font-size: 1.2rem;
        color: #ffd700;
        display: flex;
        align-items: center;
        gap: 0.25rem;
        margin-bottom: 0.5rem;
    }

    .review-comment {
        margin: 1.5rem 0;
        padding: 1.5rem;
        background: #f8fafc;
        border-radius: 12px;
        border-left: 4px solid var(--secondary);
        line-height: 1.6;
        color: var(--dark);
    }

    .review-actions {
        margin-top: 1.5rem;
    }

    .edit-review-form {
        margin-top: 1.5rem;
        padding: 2rem;
        background: rgba(248, 250, 252, 0.8);
        border-radius: 12px;
        border: 2px dashed #e2e8f0;
        animation: slideDown 0.3s ease;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .edit-review-form select {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid #e2e8f0;
        border-radius: 8px;
        font-size: 1rem;
        background: white;
        transition: var(--transition);
    }

    .edit-review-form select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
    }

    .form-actions {
        display: flex;
        gap: 1rem;
        margin-top: 1.5rem;
        flex-wrap: wrap;
    }

    .btn {
        padding: 12px 24px;
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

    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        background: white;
        border-radius: 16px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        border: 2px dashed #e2e8f0;
    }

    .empty-state h3 {
        font-size: 1.8rem;
        color: var(--dark);
        margin-bottom: 1rem;
        font-weight: 700;
    }

    .empty-state p {
        color: var(--gray);
        font-size: 1.1rem;
        margin-bottom: 2rem;
        line-height: 1.6;
    }

    .empty-state-icon {
        font-size: 4rem;
        margin-bottom: 1.5rem;
        opacity: 0.7;
    }

    .no-previous {
        text-align: center;
        padding: 3rem 2rem;
        color: var(--gray);
        font-style: italic;
        background: #f8fafc;
        border-radius: 12px;
        border: 2px dashed #e2e8f0;
    }

    @media (max-width: 768px) {
        .container {
            padding: 0 1rem;
            margin: 1rem auto;
        }
        
        h1 {
            font-size: 2rem;
            flex-direction: column;
            text-align: center;
        }
        
        .review-section {
            padding: 1.5rem;
        }
        
        .lot-stats {
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        
        .star-rating {
            gap: 0.25rem;
        }
        
        .star-rating label {
            font-size: 2rem;
        }
        
        .form-actions {
            flex-direction: column;
        }
        
        .form-actions .btn {
            width: 100%;
            justify-content: center;
        }
    }

    @media (max-width: 480px) {
        .review-card {
            padding: 1.5rem;
        }
        
        .empty-state {
            padding: 2rem 1rem;
        }
        
        .empty-state h3 {
            font-size: 1.5rem;
        }
        
        .stat-value {
            font-size: 1.5rem;
        }
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .review-card {
        animation: fadeInUp 0.5s ease;
    }
</style>

<div class="container">
    <h1>Share Your Experience</h1>
    
    <?php if (isset($_GET['success'])): ?>
        <div class="alert-success">
            <?php echo $_GET['success'] == '1' ? 'Review submitted successfully!' : 'Review updated successfully!'; ?>
        </div>
    <?php endif; ?>
    
    <div class="review-layout">
        <div class="review-section">
            <h2>Pending Reviews</h2>
            <p class="section-description">Review your recent parking experiences</p>
            
            <?php if (count($reservations) > 0): ?>
                <div class="pending-reviews">
                    <?php foreach ($reservations as $reservation): ?>
                        <div class="review-card pending">
                            <div class="review-header">
                                <h3><?php echo htmlspecialchars($reservation['parking_lot_name']); ?></h3>
                                <div class="parking-info">
                                    <span class="address">📍 <?php echo htmlspecialchars($reservation['address']); ?></span>
                                    <span class="visit-date">Visited on <?php echo date('M j, Y', strtotime($reservation['end_time'])); ?></span>
                                </div>
                            </div>
                            
                            <div class="lot-stats">
                                <div class="stat">
                                    <span class="stat-value"><?php echo number_format($reservation['avg_rating'], 1); ?></span>
                                    <span class="stat-label">Average Rating</span>
                                </div>
                                <div class="stat">
                                    <span class="stat-value"><?php echo $reservation['total_reviews']; ?></span>
                                    <span class="stat-label">Total Reviews</span>
                                </div>
                            </div>
                            
                            <form method="POST" class="review-form">
                                <input type="hidden" name="parking_lot_id" value="<?php echo $reservation['parking_lot_id']; ?>">
                                
                                <div class="rating-section">
                                    <label><strong>Your Rating:</strong></label>
                                    <div class="star-rating">
                                        <input type="radio" id="rating5-<?php echo $reservation['id']; ?>" name="rating" value="5" required>
                                        <label for="rating5-<?php echo $reservation['id']; ?>">⭐</label>
                                        
                                        <input type="radio" id="rating4-<?php echo $reservation['id']; ?>" name="rating" value="4">
                                        <label for="rating4-<?php echo $reservation['id']; ?>">⭐</label>
                                        
                                        <input type="radio" id="rating3-<?php echo $reservation['id']; ?>" name="rating" value="3">
                                        <label for="rating3-<?php echo $reservation['id']; ?>">⭐</label>
                                        
                                        <input type="radio" id="rating2-<?php echo $reservation['id']; ?>" name="rating" value="2">
                                        <label for="rating2-<?php echo $reservation['id']; ?>">⭐</label>
                                        
                                        <input type="radio" id="rating1-<?php echo $reservation['id']; ?>" name="rating" value="1">
                                        <label for="rating1-<?php echo $reservation['id']; ?>">⭐</label>
                                    </div>
                                </div>
                                
                                <div class="comment-section">
                                    <label><strong>Your Review:</strong></label>
                                    <textarea name="comment" placeholder="Share your experience with this parking lot... What did you like? What could be improved?" 
                                              required rows="4"></textarea>
                                    <div class="char-count">0/500 characters</div>
                                </div>
                                
                                <button type="submit" name="submit_review" class="btn btn-large">Submit Review</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-pending">
                    <div class="empty-state">
                        <h3>🎉 All Caught Up!</h3>
                        <p>You've reviewed all your recent parking experiences.</p>
                        <p>Your feedback helps other drivers find the best parking spots.</p>
                        <a href="my_reservations.php" class="btn">View Reservation History</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="review-section">
            <h2>Your Previous Reviews</h2>
            
            <?php if (count($previous_reviews) > 0): ?>
                <div class="previous-reviews">
                    <?php foreach ($previous_reviews as $review): ?>
                        <div class="review-card previous">
                            <div class="review-header">
                                <h3><?php echo htmlspecialchars($review['parking_lot_name']); ?></h3>
                                <div class="review-meta">
                                    <span class="rating-display">
                                        <?php for($i = 1; $i <= 5; $i++): ?>
                                            <?php if($i <= $review['rating']): ?>
                                                ⭐
                                            <?php else: ?>
                                                ☆
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                        (<?php echo $review['rating']; ?>.0)
                                    </span>
                                    <span class="review-date">Reviewed on <?php echo date('M j, Y', strtotime($review['created_at'])); ?></span>
                                </div>
                            </div>
                            
                            <div class="review-comment">
                                <p><?php echo htmlspecialchars($review['comment']); ?></p>
                            </div>
                            
                            <div class="review-actions">
                                <button type="button" class="btn btn-small btn-secondary" 
                                        onclick="editReview(<?php echo $review['id']; ?>, <?php echo $review['rating']; ?>, '<?php echo addslashes($review['comment']); ?>')">
                                    Edit Review
                                </button>
                            </div>
                            
                            <div class="edit-review-form" id="edit-form-<?php echo $review['id']; ?>" style="display: none;">
                                <form method="POST">
                                    <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                    
                                    <div class="rating-section">
                                        <label><strong>Update Rating:</strong></label>
                                        <select name="rating" required>
                                            <option value="5" <?php echo $review['rating'] == 5 ? 'selected' : ''; ?>>5 ⭐ - Excellent</option>
                                            <option value="4" <?php echo $review['rating'] == 4 ? 'selected' : ''; ?>>4 ⭐ - Very Good</option>
                                            <option value="3" <?php echo $review['rating'] == 3 ? 'selected' : ''; ?>>3 ⭐ - Good</option>
                                            <option value="2" <?php echo $review['rating'] == 2 ? 'selected' : ''; ?>>2 ⭐ - Fair</option>
                                            <option value="1" <?php echo $review['rating'] == 1 ? 'selected' : ''; ?>>1 ⭐ - Poor</option>
                                        </select>
                                    </div>
                                    
                                    <div class="comment-section">
                                        <label><strong>Update Review:</strong></label>
                                        <textarea name="comment" required rows="4"><?php echo htmlspecialchars($review['comment']); ?></textarea>
                                    </div>
                                    
                                    <div class="form-actions">
                                        <button type="submit" name="edit_review" class="btn btn-small">Update Review</button>
                                        <button type="button" class="btn btn-small btn-secondary" 
                                                onclick="cancelEdit(<?php echo $review['id']; ?>)">
                                            Cancel
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-previous">
                    <p>You haven't written any reviews yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function editReview(reviewId, currentRating, currentComment) {
    document.querySelector(`#edit-form-${reviewId}`).style.display = 'block';
}

function cancelEdit(reviewId) {
    document.querySelector(`#edit-form-${reviewId}`).style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    const textareas = document.querySelectorAll('textarea[name="comment"]');
    textareas.forEach(textarea => {
        const charCount = textarea.parentElement.querySelector('.char-count');
        if (charCount) {
            textarea.addEventListener('input', function() {
                const length = this.value.length;
                charCount.textContent = `${length}/500 characters`;
                if (length > 500) {
                    charCount.style.color = '#f44336';
                } else {
                    charCount.style.color = '#666';
                }
            });
        }
    });
});
</script>

</body>
</html>