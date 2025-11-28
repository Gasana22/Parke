<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'operator') {
    header("Location: index.php");
    exit();
}

$operator_id = $_SESSION['user_id'];
$success_message = $error_message = '';

$lot_stmt = $pdo->prepare("SELECT id, name FROM parking_lots WHERE operator_id = ? LIMIT 1");
$lot_stmt->execute([$operator_id]);
$parking_lot = $lot_stmt->fetch();

if (!$parking_lot) {
    $error_message = "No parking lot found for your account.";
} else {
    $parking_lot_id = $parking_lot['id'];

    $images_stmt = $pdo->prepare("SELECT * FROM parking_lot_images WHERE parking_lot_id = ? ORDER BY is_primary DESC, uploaded_at DESC");
    $images_stmt->execute([$parking_lot_id]);
    $images = $images_stmt->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['parking_images'])) {
        $uploaded_files = $_FILES['parking_images'];
        $upload_success = 0;
        $upload_errors = [];

        $upload_dir = 'uploads/parking_lots/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        for ($i = 0; $i < count($uploaded_files['name']); $i++) {
            if ($uploaded_files['error'][$i] === UPLOAD_ERR_OK) {
                $file_name = $uploaded_files['name'][$i];
                $file_tmp = $uploaded_files['tmp_name'][$i];
                $file_size = $uploaded_files['size'][$i];
                $file_type = $uploaded_files['type'][$i];

                $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                if (!in_array($file_type, $allowed_types)) {
                    $upload_errors[] = "File '$file_name' is not a valid image type.";
                    continue;
                }

                if ($file_size > 5 * 1024 * 1024) {
                    $upload_errors[] = "File '$file_name' is too large. Maximum size is 5MB.";
                    continue;
                }
                $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
                $new_filename = uniqid() . '_' . $parking_lot_id . '.' . $file_extension;
                $destination = $upload_dir . $new_filename;

                if (move_uploaded_file($file_tmp, $destination)) {
                    try {
                        $is_primary = (count($images) === 0 && $i === 0) ? 1 : 0;

                        $insert_stmt = $pdo->prepare("
                            INSERT INTO parking_lot_images (parking_lot_id, image_path, is_primary) 
                            VALUES (?, ?, ?)
                        ");
                        $insert_stmt->execute([$parking_lot_id, $destination, $is_primary]);
                        $upload_success++;
                    } catch (PDOException $e) {
                        $upload_errors[] = "Error saving '$file_name' to database.";
                        unlink($destination);
                    }
                } else {
                    $upload_errors[] = "Error uploading '$file_name'.";
                }
            } elseif ($uploaded_files['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                $upload_errors[] = "Error with file '$file_name': " . $uploaded_files['error'][$i];
            }
        }

        if ($upload_success > 0) {
            $success_message = "Successfully uploaded $upload_success image(s).";
        }
        if (!empty($upload_errors)) {
            $error_message = implode('<br>', $upload_errors);
        }

        $images_stmt->execute([$parking_lot_id]);
        $images = $images_stmt->fetchAll();
    }

    if (isset($_GET['set_primary'])) {
        $image_id = $_GET['set_primary'];
        
        try {
            $reset_stmt = $pdo->prepare("UPDATE parking_lot_images SET is_primary = 0 WHERE parking_lot_id = ?");
            $reset_stmt->execute([$parking_lot_id]);
            
            $primary_stmt = $pdo->prepare("UPDATE parking_lot_images SET is_primary = 1 WHERE id = ? AND parking_lot_id = ?");
            $primary_stmt->execute([$image_id, $parking_lot_id]);
            
            $success_message = "Primary image updated successfully!";
            
            $images_stmt->execute([$parking_lot_id]);
            $images = $images_stmt->fetchAll();
        } catch (PDOException $e) {
            $error_message = "Error setting primary image: " . $e->getMessage();
        }
    }

    if (isset($_GET['delete_image'])) {
        $image_id = $_GET['delete_image'];
        
        try {
            $get_image_stmt = $pdo->prepare("SELECT image_path FROM parking_lot_images WHERE id = ? AND parking_lot_id = ?");
            $get_image_stmt->execute([$image_id, $parking_lot_id]);
            $image_to_delete = $get_image_stmt->fetch();
            
            if ($image_to_delete) {
                $delete_stmt = $pdo->prepare("DELETE FROM parking_lot_images WHERE id = ? AND parking_lot_id = ?");
                $delete_stmt->execute([$image_id, $parking_lot_id]);
                
                if (file_exists($image_to_delete['image_path'])) {
                    unlink($image_to_delete['image_path']);
                }
                
                $success_message = "Image deleted successfully!";
                
                $images_stmt->execute([$parking_lot_id]);
                $images = $images_stmt->fetchAll();
            }
        } catch (PDOException $e) {
            $error_message = "Error deleting image: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Parking Lot Images - Parke</title>
  <style>
:root {
    --primary: #6366f1;
    --primary-dark: #4f46e5;
    --secondary: #10b981;
    --accent: #f59e0b;
    --danger: #ef4444;
    --dark: #1e293b;
    --light: #f8fafc;
    --gray: #64748b;
    --glass: rgba(255, 255, 255, 0.1);
    --shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Inter', 'Segoe UI', 'Arial', sans-serif;
}

body {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    color: var(--dark);
    line-height: 1.6;
}

.header {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 1.5rem 2rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    position: sticky;
    top: 0;
    z-index: 100;
}

.header h1 {
    font-size: 1.8rem;
    font-weight: 800;
    color: var(--dark);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.nav-links {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.nav-links a {
    text-decoration: none;
    color: var(--dark);
    padding: 10px 18px;
    border-radius: 25px;
    transition: var(--transition);
    font-weight: 600;
    font-size: 0.95rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    background: rgba(99, 102, 241, 0.05);
}

.nav-links a:hover {
    background: var(--primary);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
}

.container {
    max-width: 1400px;
    margin: 2rem auto;
    padding: 0 2rem;
}

.card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 2.5rem;
    border-radius: 20px;
    box-shadow: var(--shadow);
    margin-bottom: 2rem;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.card h2 {
    font-size: 2.2rem;
    font-weight: 800;
    margin-bottom: 0.5rem;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.card h3 {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 1.5rem;
    color: var(--dark);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.lot-info {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    padding: 2.5rem;
    border-radius: 16px;
    margin-bottom: 2rem;
    color: white;
    box-shadow: 0 8px 25px rgba(99, 102, 241, 0.3);
    position: relative;
    overflow: hidden;
}

.lot-info::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: rgba(255, 255, 255, 0.3);
}

.lot-info h2 {
    font-size: 1.8rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
    color: white;
    -webkit-text-fill-color: white;
    background: none;
}

.lot-info p {
    opacity: 0.9;
    font-size: 1.1rem;
}

.upload-form {
    background: white;
    padding: 2.5rem;
    border-radius: 16px;
    margin-bottom: 2rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    border-left: 4px solid var(--secondary);
    position: relative;
    overflow: hidden;
}

.upload-form::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--secondary);
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
    font-size: 0.95rem;
    box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
}

.btn:hover {
    background: var(--primary-dark);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
}

.btn-success {
    background: var(--secondary);
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
}

.btn-success:hover {
    background: #0da271;
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
}

.btn-warning {
    background: var(--accent);
    box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
}

.btn-warning:hover {
    background: #e68900;
    box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
}

.btn-danger {
    background: var(--danger);
    box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
}

.btn-danger:hover {
    background: #dc2626;
    box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
}

.btn-small {
    padding: 8px 16px;
    font-size: 0.85rem;
}

.message {
    padding: 1.5rem;
    border-radius: 12px;
    margin-bottom: 2rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    animation: slideIn 0.5s ease-out;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.success {
    background: rgba(16, 185, 129, 0.1);
    color: #065f46;
    border-left: 4px solid var(--secondary);
}

.error {
    background: rgba(239, 68, 68, 0.1);
    color: #7f1d1d;
    border-left: 4px solid var(--danger);
}

.images-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.5rem;
    margin-top: 1.5rem;
}

.image-card {
    background: white;
    border-radius: 16px;
    overflow: hidden;
    position: relative;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    transition: var(--transition);
    border: 2px solid transparent;
}

.image-card.primary {
    border-color: var(--secondary);
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
}

.image-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 12px 30px rgba(0,0,0,0.15);
}

.image-card img {
    width: 100%;
    height: 200px;
    object-fit: cover;
    display: block;
    transition: transform 0.3s ease;
}

.image-card:hover img {
    transform: scale(1.05);
}

.image-actions {
    padding: 1.5rem;
    background: white;
    display: flex;
    gap: 0.75rem;
    justify-content: space-between;
    align-items: center;
    border-top: 1px solid #f1f5f9;
}

.primary-badge {
    background: linear-gradient(135deg, var(--secondary), #0da271);
    color: white;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.upload-info {
    background: rgba(99, 102, 241, 0.05);
    padding: 1.5rem;
    border-radius: 12px;
    margin: 1.5rem 0;
    color: var(--dark);
    border-left: 4px solid var(--primary);
}

.upload-info ul {
    margin: 0.5rem 0 0 1rem;
}

.upload-info li {
    margin-bottom: 0.5rem;
    color: var(--gray);
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    font-weight: 600;
    color: var(--dark);
    margin-bottom: 0.75rem;
    font-size: 0.95rem;
}

input[type="file"] {
    width: 100%;
    padding: 1rem 1.2rem;
    border: 2px dashed var(--secondary);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.8);
    transition: var(--transition);
    cursor: pointer;
    font-size: 1rem;
}

input[type="file"]:hover {
    border-color: var(--primary);
    background: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
}

.image-meta {
    padding: 1rem;
    background: #f8fafc;
    font-size: 0.8rem;
    color: var(--gray);
    border-top: 1px solid #e2e8f0;
}

.empty-state {
    text-align: center;
    padding: 3rem 2rem;
    color: var(--gray);
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    margin: 1.5rem 0;
}

.empty-state h3 {
    font-size: 1.5rem;
    margin-bottom: 1rem;
    color: var(--dark);
}

@media (max-width: 1024px) {
    .images-grid {
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    }
}

@media (max-width: 768px) {
    .header {
        flex-direction: column;
        gap: 1rem;
        padding: 1rem;
    }
    
    .nav-links {
        justify-content: center;
        width: 100%;
    }
    
    .container {
        padding: 0 1rem;
        margin: 1rem auto;
    }
    
    .card {
        padding: 1.5rem;
    }
    
    .lot-info {
        padding: 1.5rem;
    }
    
    .upload-form {
        padding: 1.5rem;
    }
    
    .images-grid {
        grid-template-columns: 1fr;
    }
    
    .image-actions {
        flex-direction: column;
        gap: 0.5rem;
        align-items: stretch;
    }
    
    .image-actions .btn {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .nav-links {
        flex-direction: column;
        width: 100%;
    }
    
    .nav-links a {
        justify-content: center;
    }
    
    .card h2 {
        font-size: 1.8rem;
    }
    
    .lot-info h2 {
        font-size: 1.5rem;
    }
}
</style>
</head>
<body>
    <div class="header">
        <h1>Manage Parking Lot Images</h1>
        <div class="nav-links">
            <a href="operator_dashboard.php">← Dashboard</a>
            <a href="manage_slots.php">Manage Slots</a>
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
        
        <?php if (!$parking_lot): ?>
            <div class="card">
                <div class="message error">
                    <h3>🚫 No Parking Lot Found</h3>
                    <p>You need to have a parking lot assigned to manage images.</p>
                    <a href="operator_dashboard.php" class="btn">Return to Dashboard</a>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="lot-info">
                    <h2>🏢 <?php echo htmlspecialchars($parking_lot['name']); ?></h2>
                    <p>Upload and manage images of your parking lot. These images will be visible to drivers when they browse available parking.</p>
                </div>

                <div class="upload-form">
                    <h3>📸 Upload New Images</h3>
                    <form method="POST" enctype="multipart/form-data">
                        <div style="margin-bottom: 1rem;">
                            <label for="parking_images" style="display: block; margin-bottom: 0.5rem; font-weight: bold;">
                                Select Images (Multiple files allowed)
                            </label>
                            <input type="file" id="parking_images" name="parking_images[]" multiple 
                                   accept="image/jpeg, image/jpg, image/png, image/gif, image/webp" required
                                   style="width: 100%; padding: 8px; border: 2px dashed #4CAF50; border-radius: 5px;">
                        </div>
                        
                        <div class="upload-info">
                            <strong>Upload Guidelines:</strong>
                            <ul style="margin: 0.5rem 0 0 1rem;">
                                <li>Supported formats: JPG, PNG, GIF, WebP</li>
                                <li>Maximum file size: 5MB per image</li>
                                <li>First uploaded image will be set as primary</li>
                                <li>You can change the primary image later</li>
                            </ul>
                        </div>
                        
                        <button type="submit" class="btn btn-success">Upload Images</button>
                    </form>
                </div>

                <h3>🖼️ Current Images (<?php echo count($images); ?>)</h3>
                <?php if ($images): ?>
                    <div class="images-grid">
                        <?php foreach ($images as $image): ?>
                        <div class="image-card <?php echo $image['is_primary'] ? 'primary' : ''; ?>">
                            <img src="<?php echo htmlspecialchars($image['image_path']); ?>" 
                                 alt="Parking Lot Image" 
                                 onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZGRkIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzk5OSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZHk9Ii4zZW0iPkltYWdlIE5vdCBGb3VuZDwvdGV4dD48L3N2Zz4='">
                            
                            <div class="image-actions">
                                <div>
                                    <?php if ($image['is_primary']): ?>
                                        <span class="primary-badge">★ Primary</span>
                                    <?php else: ?>
                                        <a href="manage_images.php?set_primary=<?php echo $image['id']; ?>" 
                                           class="btn btn-warning btn-small"
                                           onclick="return confirm('Set this image as primary?')">
                                            Set as Primary
                                        </a>
                                    <?php endif; ?>
                                </div>
                                
                                <a href="manage_images.php?delete_image=<?php echo $image['id']; ?>" 
                                   class="btn btn-danger btn-small"
                                   onclick="return confirm('Are you sure you want to delete this image?')">
                                    Delete
                                </a>
                            </div>
                            
                            <div style="padding: 0.5rem; background: #f8f9fa; font-size: 0.8rem; color: #666;">
                                Uploaded: <?php echo date('M j, Y g:i A', strtotime($image['uploaded_at'])); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 3rem; color: #666;">
                        <h3>📷 No Images Yet</h3>
                        <p>Upload some images of your parking lot to show drivers what it looks like!</p>
                        <p>Good images help attract more customers.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>