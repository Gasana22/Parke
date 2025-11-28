<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'driver') {
    header("Location: index.php");
    exit();
}

$driver_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM drivers WHERE id = ?");
$stmt->execute([$driver_id]);
$driver = $stmt->fetch();

if (isset($_POST['update_profile'])) {
    $username = $_POST['username'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $car_plate = $_POST['car_plate'];
    $check_username = $pdo->prepare("SELECT id FROM drivers WHERE username = ? AND id != ?");
    $check_username->execute([$username, $driver_id]);
    
    if ($check_username->fetch()) {
        $error = "Username already exists. Please choose a different username.";
    } else {
        $check_email = $pdo->prepare("SELECT id FROM drivers WHERE email = ? AND id != ?");
        $check_email->execute([$email, $driver_id]);
        
        if ($check_email->fetch()) {
            $error = "Email already exists. Please use a different email address.";
        } else {
            $stmt = $pdo->prepare("UPDATE drivers SET username = ?, email = ?, phone = ?, car_plate = ? WHERE id = ?");
            $stmt->execute([$username, $email, $phone, $car_plate, $driver_id]);
            
            $_SESSION['username'] = $username;
            $_SESSION['email'] = $email;
            $_SESSION['phone'] = $phone;
            $_SESSION['car_plate'] = $car_plate;
            
            header("Location: edit_profile.php?success=1");
            exit();
        }
    }
}

if (isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (!password_verify($current_password, $driver['password'])) {
        $password_error = "Current password is incorrect.";
    } elseif ($new_password !== $confirm_password) {
        $password_error = "New passwords do not match.";
    } elseif (strlen($new_password) < 6) {
        $password_error = "New password must be at least 6 characters long.";
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE drivers SET password = ? WHERE id = ?");
        $stmt->execute([$hashed_password, $driver_id]);
        
        header("Location: edit_profile.php?success=password");
        exit();
    }
}

$page_title = "Edit Profile - Parke";
include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'Parke'; ?></title>

<style>
.profile-layout {
    display: grid;
    gap: 2rem;
}

.profile-section {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 2rem;
    border-radius: 20px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    transition: transform 0.3s ease;
}

.profile-section:hover {
    transform: translateY(-5px);
}

.profile-form, .password-form {
    max-width: 600px;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #2d3748;
}

.form-group input {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    font-size: 1rem;
    transition: all 0.3s ease;
    background: white;
}

.form-group input:focus {
    border-color: #4299e1;
    outline: none;
    box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
    transform: translateY(-2px);
}

.form-group small {
    display: block;
    margin-top: 0.5rem;
    color: #718096;
    font-size: 0.85rem;
}

.form-actions {
    display: flex;
    gap: 1rem;
    margin-top: 2rem;
}

.btn-large {
    padding: 1rem 2rem;
    font-size: 1.1rem;
    font-weight: 600;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.5rem;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 16px;
    color: white;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    transition: transform 0.3s ease;
}

.stat-item:hover {
    transform: translateY(-5px);
}

.stat-icon {
    font-size: 2rem;
}

.stat-info {
    flex: 1;
}

.stat-number {
    font-size: 1.8rem;
    font-weight: 800;
    margin-bottom: 0.25rem;
    text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
}

.stat-label {
    font-size: 0.9rem;
    opacity: 0.9;
}

.account-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
}

.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    backdrop-filter: blur(5px);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 1000;
}

.modal-content {
    background: white;
    border-radius: 20px;
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 25px 50px rgba(0,0,0,0.3);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.5rem 2rem;
    border-bottom: 1px solid #e2e8f0;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 20px 20px 0 0;
}

.modal-header h3 {
    margin: 0;
    font-weight: 700;
}

.close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: white;
    transition: transform 0.3s ease;
}

.close:hover {
    transform: scale(1.2);
}

.modal-body {
    padding: 2rem;
}

.modal-actions {
    padding: 1.5rem 2rem;
    border-top: 1px solid #e2e8f0;
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
}

.btn-danger {
    background: linear-gradient(135deg, #f56565, #e53e3e);
    box-shadow: 0 4px 15px rgba(245, 101, 101, 0.3);
}

.alert-error {
    background: linear-gradient(135deg, #fed7d7, #feb2b2);
    color: #742a2a;
    padding: 1rem 1.5rem;
    border-radius: 12px;
    margin-bottom: 1.5rem;
    border: none;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.alert-success {
    background: linear-gradient(135deg, #c6f6d5, #9ae6b4);
    color: #22543d;
    padding: 1rem 1.5rem;
    border-radius: 12px;
    margin-bottom: 1.5rem;
    border: none;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.account-actions .btn {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    justify-content: center;
    padding: 1.5rem;
    font-size: 1.1rem;
    background: rgba(255, 255, 255, 0.9);
    border: 2px solid #e2e8f0;
    color: #2d3748;
    transition: all 0.3s ease;
}

.account-actions .btn:hover {
    background: white;
    border-color: #4299e1;
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.1);
}

@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .stats-grid {
        grid-template-columns: 1fr 1fr;
    }
    
    .account-actions {
        grid-template-columns: 1fr;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .modal-actions {
        flex-direction: column;
    }
}
</style>

<div class="container">
    <h1>Edit Profile</h1>
    
    <?php if (isset($_GET['success'])): ?>
        <div class="alert-success">
            <?php echo $_GET['success'] == '1' ? 'Profile updated successfully!' : 'Password changed successfully!'; ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert-error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <div class="profile-layout">
        <div class="profile-section">
            <h2>Profile Information</h2>
            <form method="POST" class="profile-form">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Username *</label>
                        <input type="text" name="username" value="<?php echo htmlspecialchars($driver['username']); ?>" required>
                        <small>This will be your display name</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Email Address *</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($driver['email']); ?>" required>
                        <small>We'll send important notifications to this email</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" name="phone" value="<?php echo htmlspecialchars($driver['phone']); ?>" 
                               pattern="[0-9+\-\s()]{10,}" placeholder="+1 (555) 123-4567">
                        <small>For emergency contact and notifications</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Car License Plate</label>
                        <input type="text" name="car_plate" value="<?php echo htmlspecialchars($driver['car_plate']); ?>" 
                               placeholder="ABC123" style="text-transform: uppercase;">
                        <small>Your vehicle's license plate number</small>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="update_profile" class="btn btn-large">Update Profile</button>
                    <a href="driver_dashboard.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
        
        <div class="profile-section">
            <h2>Change Password</h2>
            
            <?php if (isset($password_error)): ?>
                <div class="alert-error"><?php echo $password_error; ?></div>
            <?php endif; ?>
            
            <form method="POST" class="password-form">
                <div class="form-group">
                    <label>Current Password *</label>
                    <input type="password" name="current_password" required>
                </div>
                
                <div class="form-group">
                    <label>New Password *</label>
                    <input type="password" name="new_password" required minlength="6">
                    <small>Must be at least 6 characters long</small>
                </div>
                
                <div class="form-group">
                    <label>Confirm New Password *</label>
                    <input type="password" name="confirm_password" required minlength="6">
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="change_password" class="btn btn-large">Change Password</button>
                </div>
            </form>
        </div>
        
        <div class="profile-section">
            <h2>Account Statistics</h2>
            <div class="stats-grid">
                <?php
                $reservations_count = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = ?");
                $reservations_count->execute([$driver_id]);
                $total_reservations = $reservations_count->fetchColumn();
                
                $active_reservations = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status = 'active'");
                $active_reservations->execute([$driver_id]);
                $active_count = $active_reservations->fetchColumn();
                
                $total_spent = $pdo->prepare("SELECT SUM(total_price) FROM reservations WHERE user_id = ? AND status IN ('completed', 'active')");
                $total_spent->execute([$driver_id]);
                $spent_amount = $total_spent->fetchColumn() ?? 0;
                
                $reviews_count = $pdo->prepare("SELECT COUNT(*) FROM reviews WHERE user_id = ?");
                $reviews_count->execute([$driver_id]);
                $total_reviews = $reviews_count->fetchColumn();
                ?>
                
                <div class="stat-item">
                    <div class="stat-icon">📅</div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $total_reservations; ?></div>
                        <div class="stat-label">Total Reservations</div>
                    </div>
                </div>
                
                <div class="stat-item">
                    <div class="stat-icon">🔄</div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $active_count; ?></div>
                        <div class="stat-label">Active Reservations</div>
                    </div>
                </div>
                
                <div class="stat-item">
                    <div class="stat-icon">💰</div>
                    <div class="stat-info">
                        <div class="stat-number">$<?php echo number_format($spent_amount, 2); ?></div>
                        <div class="stat-label">Total Spent</div>
                    </div>
                </div>
                
                <div class="stat-item">
                    <div class="stat-icon">⭐</div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $total_reviews; ?></div>
                        <div class="stat-label">Reviews Written</div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="profile-section">
            <h2>Account Actions</h2>
            <div class="account-actions">
                <button type="button" class="btn btn-secondary" onclick="exportData()">📥 Export My Data</button>
                <button type="button" class="btn btn-secondary" onclick="showDeleteModal()">🗑️ Delete Account</button>
                <a href="privacy_settings.php" class="btn btn-secondary">🔒 Privacy Settings</a>
                <a href="notification_settings.php" class="btn btn-secondary">🔔 Notification Settings</a>
            </div>
        </div>
    </div>
</div>

<div id="deleteModal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Delete Account</h3>
            <button type="button" class="close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p><strong>Warning:</strong> This action cannot be undone. All your data including reservations, reviews, and payment methods will be permanently deleted.</p>
            <p>Are you sure you want to delete your account?</p>
        </div>
        <div class="modal-actions">
            <form method="POST" action="delete_account.php">
                <button type="submit" class="btn btn-danger">Yes, Delete My Account</button>
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
            </form>
        </div>
    </div>
</div>

<script>
function showDeleteModal() {
    document.getElementById('deleteModal').style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

function exportData() {
    alert('Data export feature would generate a file with all your account data, reservations, and reviews.');
}

window.onclick = function(event) {
    const modal = document.getElementById('deleteModal');
    if (event.target === modal) {
        closeDeleteModal();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const phoneInput = document.querySelector('input[name="phone"]');
    if (phoneInput) {
        phoneInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 0) {
                if (value.length <= 3) {
                    value = value;
                } else if (value.length <= 6) {
                    value = `(${value.slice(0,3)}) ${value.slice(3)}`;
                } else {
                    value = `(${value.slice(0,3)}) ${value.slice(3,6)}-${value.slice(6,10)}`;
                }
            }
            e.target.value = value;
        });
    }
});
</script>

</body>
</html>