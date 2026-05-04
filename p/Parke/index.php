<?php
// index.php
require_once 'includes/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PARKE - Find & Reserve Parking Spots Instantly</title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary: #10b981;
            --secondary-dark: #0da271;
            --dark: #1e293b;
            --light: #f8fafc;
            --gray: #64748b;
            --gray-light: #e2e8f0;
            --shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 20px 40px rgba(0, 0, 0, 0.1);
            --radius: 12px;
            --radius-lg: 20px;
            --transition: all 0.3s ease;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            line-height: 1.6;
            color: var(--dark);
            overflow-x: hidden;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            color: var(--dark);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 24px;
        }
        
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .logo i {
            font-size: 2rem;
        }
        
        .nav-links {
            display: flex;
            gap: 1.5rem;
            align-items: center;
        }
        
        .nav-links a {
            color: var(--dark);
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 8px;
            transition: var(--transition);
            font-weight: 500;
        }
        
        .nav-links a:hover {
            background: var(--gray-light);
            color: var(--primary);
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 28px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: var(--radius);
            border: none;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            font-size: 1rem;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }
        
        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.3);
        }
        
        .btn-secondary {
            background: var(--secondary);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
        }
        
        .btn-secondary:hover {
            background: var(--secondary-dark);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.3);
        }
        
        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
            box-shadow: none;
        }
        
        .btn-outline:hover {
            background: rgba(37, 99, 235, 0.05);
        }
        
        .hero {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            padding: 120px 0 100px;
            position: relative;
            overflow: hidden;
        }
        
        .hero::before {
            content: '';
            position: absolute;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.1) 0%, rgba(37, 99, 235, 0) 70%);
            top: -300px;
            right: -200px;
            z-index: 0;
        }
        
        .hero::after {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(16, 185, 129, 0.1) 0%, rgba(16, 185, 129, 0) 70%);
            bottom: -200px;
            left: -100px;
            z-index: 0;
        }
        
        .hero-container {
            display: flex;
            align-items: center;
            gap: 60px;
            position: relative;
            z-index: 1;
        }
        
        .hero-content {
            flex: 1;
        }
        
        .hero-visual {
            flex: 1;
            display: flex;
            justify-content: center;
        }
        
        .parking-visual {
            width: 100%;
            max-width: 500px;
            background: white;
            border-radius: var(--radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }
        
        .parking-visual::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 8px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }
        
        .parking-spots {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 20px;
        }
        
        .parking-spot {
            height: 60px;
            background: var(--gray-light);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            position: relative;
            transition: var(--transition);
        }
        
        .parking-spot.available {
            background: rgba(16, 185, 129, 0.15);
            color: var(--secondary-dark);
        }
        
        .parking-spot.occupied {
            background: rgba(239, 68, 68, 0.15);
            color: #dc2626;
        }
        
        .parking-spot.reserved {
            background: rgba(245, 158, 11, 0.15);
            color: #d97706;
        }
        
        .hero h1 {
            font-size: 3.5rem;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 1.5rem;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        .hero p {
            font-size: 1.25rem;
            color: var(--gray);
            margin-bottom: 2.5rem;
            max-width: 600px;
        }
        
        .hero-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .features {
            padding: 100px 0;
            background: white;
        }
        
        .section-header {
            text-align: center;
            margin-bottom: 4rem;
        }
        
        .section-header h2 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 1rem;
            color: var(--dark);
        }
        
        .section-header p {
            font-size: 1.125rem;
            color: var(--gray);
            max-width: 600px;
            margin: 0 auto;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 2rem;
        }
        
        .feature-card {
            padding: 2.5rem 2rem;
            background: white;
            border-radius: var(--radius);
            transition: var(--transition);
            border: 1px solid var(--gray-light);
            position: relative;
            overflow: hidden;
        }
        
        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(to bottom, var(--primary), var(--secondary));
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow-lg);
        }
        
        .feature-card i {
            font-size: 3rem;
            margin-bottom: 1.5rem;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        .feature-card h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: var(--dark);
        }
        
        .feature-card p {
            color: var(--gray);
        }
        
        .stats {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 80px 0;
            text-align: center;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
        }
        
        .stat-item h3 {
            font-size: 3rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
        }
        
        .stat-item p {
            font-size: 1.125rem;
            opacity: 0.9;
        }
        
        .cta {
            padding: 100px 0;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            text-align: center;
        }
        
        .cta-container {
            max-width: 700px;
            margin: 0 auto;
        }
        
        .cta h2 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 1rem;
            color: var(--dark);
        }
        
        .cta p {
            font-size: 1.25rem;
            color: var(--gray);
            margin-bottom: 2.5rem;
        }
        
        .footer {
            background: var(--dark);
            color: white;
            padding: 4rem 0 2rem;
        }
        
        .footer-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 3rem;
            margin-bottom: 3rem;
        }
        
        .footer-logo {
            font-size: 1.75rem;
            font-weight: 800;
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 1rem;
        }
        
        .footer-links h4 {
            font-size: 1.25rem;
            margin-bottom: 1.5rem;
        }
        
        .footer-links ul {
            list-style: none;
        }
        
        .footer-links li {
            margin-bottom: 0.75rem;
        }
        
        .footer-links a {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: var(--transition);
        }
        
        .footer-links a:hover {
            color: white;
            padding-left: 5px;
        }
        
        .footer-bottom {
            text-align: center;
            padding-top: 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
        }
        
        @media (max-width: 1024px) {
            .hero h1 {
                font-size: 2.75rem;
            }
            
            .hero-container {
                flex-direction: column;
                text-align: center;
            }
            
            .hero p {
                margin: 0 auto 2.5rem;
            }
            
            .hero-buttons {
                justify-content: center;
            }
        }
        
        @media (max-width: 768px) {
            .header-container {
                flex-direction: column;
                gap: 1rem;
            }
            
            .nav-links {
                width: 100%;
                justify-content: center;
                flex-wrap: wrap;
            }
            
            .hero {
                padding: 80px 0 60px;
            }
            
            .hero h1 {
                font-size: 2.25rem;
            }
            
            .hero p {
                font-size: 1.125rem;
            }
            
            .section-header h2 {
                font-size: 2rem;
            }
            
            .features, .stats, .cta {
                padding: 60px 0;
            }
        }
        
        @media (max-width: 480px) {
            .hero h1 {
                font-size: 1.875rem;
            }
            
            .hero-buttons {
                flex-direction: column;
            }
            
            .hero-buttons .btn {
                width: 100%;
            }
            
            .features-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <header class="header">
        <div class="container header-container">
            <a href="index.php" class="logo">
                <i class="fas fa-parking"></i> PARKE
            </a>
            
            <nav class="nav-links">
    <a href="index.php">Home</a>
    <a href="find_parking.php">Find Parking</a>
    <?php if (is_logged_in()): ?>
    <?php $user = get_logged_in_user(); ?>
    <a href="dashboard.php">Dashboard</a>
    <a href="logout.php">Logout</a>
    <span style="color: white; padding: 5px 10px;">
        Welcome, <?php echo htmlspecialchars($user['username']); ?>
    </span>
<?php else: ?>
    <a href="login.php">Login</a>
    <a href="register.php" class="btn">Register</a>
<?php endif; ?>
</nav>
        </div>
    </header>
    
    <main>
        <section class="hero">
            <div class="container hero-container">
                <div class="hero-content">
                    <h1>Find & Reserve Parking in Seconds</h1>
                    <p>Real-time parking availability, instant reservations, and stress-free parking experience across your city. Save time and reduce parking frustration.</p>
                    
                    <div class="hero-buttons">
                        <?php if (!is_logged_in()): ?>
                            <a href="register.php" class="btn">
                                <i class="fas fa-user-plus"></i> Create Free Account
                            </a>
                            <a href="find_parking.php" class="btn btn-secondary">
                                <i class="fas fa-map-marker-alt"></i> Find Parking Now
                            </a>
                        <?php else: ?>
                            <a href="find_parking.php" class="btn">
                                <i class="fas fa-map-marker-alt"></i> Find Parking
                            </a>
                            <a href="dashboard.php" class="btn btn-secondary">
                                <i class="fas fa-tachometer-alt"></i> Go to Dashboard
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <div style="margin-top: 2.5rem; display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-check-circle" style="color: var(--secondary);"></i>
                            <span>No credit card required</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-check-circle" style="color: var(--secondary);"></i>
                            <span>Free to sign up</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-check-circle" style="color: var(--secondary);"></i>
                            <span>24/7 support</span>
                        </div>
                    </div>
                </div>
                
                <div class="hero-visual">
                    <div class="parking-visual">
                        <h3 style="margin-bottom: 0.5rem; color: var(--dark);">Live Parking Availability</h3>
                        <p style="color: var(--gray); margin-bottom: 1rem; font-size: 0.9rem;">Downtown District - Updated 2 min ago</p>
                        
                        <div class="parking-spots">
                            <div class="parking-spot available">P1<br><small>Available</small></div>
                            <div class="parking-spot occupied">P2<br><small>Occupied</small></div>
                            <div class="parking-spot available">P3<br><small>Available</small></div>
                            <div class="parking-spot reserved">P4<br><small>Reserved</small></div>
                            <div class="parking-spot available">P5<br><small>Available</small></div>
                            <div class="parking-spot occupied">P6<br><small>Occupied</small></div>
                        </div>
                        
                        <div style="margin-top: 1.5rem; display: flex; justify-content: space-between; font-size: 0.85rem;">
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <div style="width: 12px; height: 12px; background: rgba(16, 185, 129, 0.15); border-radius: 2px;"></div>
                                <span>Available</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <div style="width: 12px; height: 12px; background: rgba(239, 68, 68, 0.15); border-radius: 2px;"></div>
                                <span>Occupied</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <div style="width: 12px; height: 12px; background: rgba(245, 158, 11, 0.15); border-radius: 2px;"></div>
                                <span>Reserved</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        
        <section class="features">
            <div class="container">
                <div class="section-header">
                    <h2>Smart Parking Solutions</h2>
                    <p>Our platform combines advanced technology with user-friendly design to transform your parking experience.</p>
                </div>
                
                <div class="features-grid">
                    <div class="feature-card">
                        <i class="fas fa-map-marked-alt"></i>
                        <h3>Real-time Parking Maps</h3>
                        <p>Interactive maps showing available spots with live updates every minute. Filter by price, distance, and amenities.</p>
                    </div>
                    <div class="feature-card">
                        <i class="fas fa-clock"></i>
                        <h3>Instant Reservations</h3>
                        <p>Reserve your spot in seconds with our one-tap booking system. Guaranteed parking when you arrive.</p>
                    </div>
                    <div class="feature-card">
                        <i class="fas fa-mobile-alt"></i>
                        <h3>Mobile-Optimized</h3>
                        <p>Access our platform from any device with a seamless experience designed for on-the-go use.</p>
                    </div>
                    <div class="feature-card">
                        <i class="fas fa-shield-alt"></i>
                        <h3>Secure & Reliable</h3>
                        <p>Your payment and personal data are protected with bank-level security and encryption.</p>
                    </div>
                    <div class="feature-card">
                        <i class="fas fa-chart-line"></i>
                        <h3>Smart Analytics</h3>
                        <p>Get insights on parking patterns, favorite locations, and cost-saving opportunities.</p>
                    </div>
                    <div class="feature-card">
                        <i class="fas fa-headset"></i>
                        <h3>24/7 Support</h3>
                        <p>Our customer support team is available around the clock to assist with any parking needs.</p>
                    </div>
                </div>
            </div>
        </section>
        
        <section class="stats">
            <div class="container">
                <div class="stats-grid">
                    <div class="stat-item">
                        <h3>50,000+</h3>
                        <p>Parking Spots Available</p>
                    </div>
                    <div class="stat-item">
                        <h3>75%</h3>
                        <p>Average Time Saved</p>
                    </div>
                    <div class="stat-item">
                        <h3>200+</h3>
                        <p>Cities Covered</p>
                    </div>
                    <div class="stat-item">
                        <h3>4.8★</h3>
                        <p>User Rating</p>
                    </div>
                </div>
            </div>
        </section>
        
        <section class="cta">
            <div class="container cta-container">
                <h2>Ready to Park Smarter?</h2>
                <p>Join thousands of users who have transformed their parking experience. Sign up today and get your first reservation free.</p>
                
                <div class="hero-buttons">
                    <?php if (!is_logged_in()): ?>
                        <a href="register.php" class="btn">
                            <i class="fas fa-rocket"></i> Start Free Trial
                        </a>
                        <a href="find_parking.php" class="btn btn-outline">
                            <i class="fas fa-play-circle"></i> See Demo
                        </a>
                    <?php else: ?>
                        <a href="find_parking.php" class="btn">
                            <i class="fas fa-map-marker-alt"></i> Find Parking Now
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>
    
    <footer class="footer">
        <div class="container">
            <div class="footer-container">
                <div>
                    <a href="index.php" class="footer-logo">
                        <i class="fas fa-parking"></i> PARKE
                    </a>
                    <p style="color: rgba(255, 255, 255, 0.7); margin-bottom: 1.5rem;">Making parking simple, efficient, and stress-free for everyone.</p>
                    <div style="display: flex; gap: 1rem;">
                        <a href="#" style="color: white; font-size: 1.25rem;"><i class="fab fa-facebook"></i></a>
                        <a href="#" style="color: white; font-size: 1.25rem;"><i class="fab fa-twitter"></i></a>
                        <a href="#" style="color: white; font-size: 1.25rem;"><i class="fab fa-instagram"></i></a>
                        <a href="#" style="color: white; font-size: 1.25rem;"><i class="fab fa-linkedin"></i></a>
                    </div>
                </div>
                
                <div class="footer-links">
                    <h4>Product</h4>
                    <ul>
                        <li><a href="find_parking.php">Find Parking</a></li>
                        <li><a href="#">How It Works</a></li>
                        <li><a href="#">Pricing</a></li>
                        <li><a href="#">Cities</a></li>
                    </ul>
                </div>
                
                <div class="footer-links">
                    <h4>Company</h4>
                    <ul>
                        <li><a href="#">About Us</a></li>
                        <li><a href="#">Careers</a></li>
                        <li><a href="#">Press</a></li>
                        <li><a href="#">Blog</a></li>
                    </ul>
                </div>
                
                <div class="footer-links">
                    <h4>Support</h4>
                    <ul>
                        <li><a href="#">Help Center</a></li>
                        <li><a href="#">Contact Us</a></li>
                        <li><a href="#">Privacy Policy</a></li>
                        <li><a href="#">Terms of Service</a></li>
                    </ul>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> PARKE. All rights reserved.</p>
                <p style="margin-top: 0.5rem;">Find parking spots easily and efficiently</p>
            </div>
        </div>
    </footer>
</body>
</html>