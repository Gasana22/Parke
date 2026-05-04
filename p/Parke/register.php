<?php
// register.php
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Redirect if already logged in
redirect_if_logged_in();

$error = '';
$success = '';

// Initialize variables as empty strings
$username = '';
$email = '';
$phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data and ensure they are strings
    $username = isset($_POST['username']) ? (is_string($_POST['username']) ? trim($_POST['username']) : '') : '';
    $email = isset($_POST['email']) ? (is_string($_POST['email']) ? trim($_POST['email']) : '') : '';
    $password = isset($_POST['password']) ? (is_string($_POST['password']) ? $_POST['password'] : '') : '';
    $confirm_password = isset($_POST['confirm_password']) ? (is_string($_POST['confirm_password']) ? $_POST['confirm_password'] : '') : '';
    $phone = isset($_POST['phone']) ? (is_string($_POST['phone']) ? trim($_POST['phone']) : '') : '';
    
    // Basic validation
    if (empty($username) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = "All required fields must be filled!";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match!";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long!";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address!";
    } else {
        // Sanitize inputs
        if (function_exists('sanitize_input')) {
            $username = sanitize_input($username);
            $email = sanitize_input($email);
            $phone = sanitize_input($phone);
        }
        
        // Register user using your existing register_user function
        $result = register_user($username, $email, $password, $phone);
        
        if ($result && isset($result['success']) && $result['success'] === true) {
            $success = $result['message'];
            // Clear form
            $username = '';
            $email = '';
            $phone = '';
            
            // Optional: Redirect to login page after 2 seconds
            header("refresh:2;url=login.php");
        } else {
            // Handle error from register_user function
            if (isset($result['message'])) {
                $error = $result['message'];
            } else {
                $error = "Registration failed. Please try again.";
            }
        }
    }
}

// Helper function for safe output
function safe_output($value) {
    if (is_array($value)) {
        return '';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Smart Parking Finder</title>
    <!-- Bootstrap CSS for alert styling (optional) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            width: 100%;
            max-width: 450px;
        }
        
        .card {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .card h2 {
            text-align: center;
            margin-bottom: 30px;
            color: #333;
            font-size: 28px;
            font-weight: 600;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 14px;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-group input.error {
            border-color: #f56565;
        }
        
        .form-group input.valid {
            border-color: #48bb78;
        }
        
        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease;
            margin-top: 10px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-size: 14px;
        }
        
        .alert-error {
            background: #fff5f5;
            color: #c53030;
            border: 1px solid #feb2b2;
        }
        
        .alert-success {
            background: #f0fff4;
            color: #276749;
            border: 1px solid #9ae6b4;
        }
        
        .alert-info {
            background: #ebf8ff;
            color: #2c5282;
            border: 1px solid #90cdf4;
        }
        
        .links {
            text-align: center;
            margin-top: 25px;
        }
        
        .links a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s ease;
        }
        
        .links a:hover {
            color: #764ba2;
            text-decoration: underline;
        }
        
        .required-field {
            color: #f56565;
            margin-left: 3px;
        }
        
        .field-note {
            font-size: 12px;
            color: #999;
            margin-top: 5px;
        }
        
        .validation-message {
            font-size: 12px;
            margin-top: 5px;
            display: none;
        }
        
        .validation-message.error {
            color: #f56565;
            display: block;
        }
        
        .validation-message.success {
            color: #48bb78;
            display: block;
        }
        
        .password-strength {
            height: 4px;
            margin-top: 5px;
            border-radius: 2px;
            transition: all 0.3s ease;
        }
        
        .password-strength.weak {
            background: #f56565;
            width: 33%;
        }
        
        .password-strength.medium {
            background: #f6ad55;
            width: 66%;
        }
        
        .password-strength.strong {
            background: #48bb78;
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h2>Create Account</h2>
            
            <?php 
            // Display any existing session messages from your auth system
            $session_message = display_message();
            if (!empty($session_message)) {
                echo $session_message;
            }
            
            // Display error messages
            if (!empty($error) && is_string($error)): 
            ?>
                <div class="alert alert-error">
                    <strong>Error!</strong> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success) && is_string($success)): ?>
                <div class="alert alert-success">
                    <strong>Success!</strong> <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                    <div style="margin-top: 10px;">
                        Redirecting to login page...
                    </div>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="registerForm" novalidate>
                <div class="form-group">
                    <label for="username">
                        Username <span class="required-field">*</span>
                    </label>
                    <input type="text" 
                           id="username" 
                           name="username" 
                           value="<?php echo safe_output($username); ?>"
                           placeholder="Enter your username"
                           pattern="[a-zA-Z0-9_]{3,50}"
                           title="Username must be 3-50 characters and can only contain letters, numbers, and underscores"
                           required>
                    <div class="field-note">3-50 characters, letters, numbers, and underscores only</div>
                    <div id="username-validation" class="validation-message"></div>
                </div>
                
                <div class="form-group">
                    <label for="email">
                        Email Address <span class="required-field">*</span>
                    </label>
                    <input type="email" 
                           id="email" 
                           name="email" 
                           value="<?php echo safe_output($email); ?>"
                           placeholder="Enter your email"
                           required>
                    <div id="email-validation" class="validation-message"></div>
                </div>
                
                <div class="form-group">
                    <label for="phone">
                        Phone Number
                    </label>
                    <input type="tel" 
                           id="phone" 
                           name="phone" 
                           value="<?php echo safe_output($phone); ?>"
                           placeholder="Enter your phone number (optional)"
                           pattern="[0-9+\-\s()]{10,20}">
                    <div class="field-note">Format: +1234567890 or (123) 456-7890</div>
                    <div id="phone-validation" class="validation-message"></div>
                </div>
                
                <div class="form-group">
                    <label for="password">
                        Password <span class="required-field">*</span>
                    </label>
                    <input type="password" 
                           id="password" 
                           name="password" 
                           placeholder="Enter your password"
                           required 
                           minlength="6">
                    <div class="field-note">Minimum 6 characters, at least 1 uppercase, 1 lowercase, and 1 number</div>
                    <div id="password-strength" class="password-strength"></div>
                    <div id="password-validation" class="validation-message"></div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">
                        Confirm Password <span class="required-field">*</span>
                    </label>
                    <input type="password" 
                           id="confirm_password" 
                           name="confirm_password" 
                           placeholder="Re-enter your password"
                           required>
                    <div id="confirm-validation" class="validation-message"></div>
                </div>
                
                <button type="submit" class="btn" id="submit-btn">Register</button>
            </form>
            
            <div class="links">
                Already have an account? <a href="login.php">Login here</a>
            </div>
        </div>
    </div>
    
    <script>
        // Get form elements
        const form = document.getElementById('registerForm');
        const username = document.getElementById('username');
        const email = document.getElementById('email');
        const phone = document.getElementById('phone');
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        const submitBtn = document.getElementById('submit-btn');
        
        // Validation functions
        function validateUsername() {
            const value = username.value;
            const validationMsg = document.getElementById('username-validation');
            const pattern = /^[a-zA-Z0-9_]{3,50}$/;
            
            if (value.length === 0) {
                validationMsg.className = 'validation-message error';
                validationMsg.textContent = 'Username is required';
                username.classList.add('error');
                username.classList.remove('valid');
                return false;
            } else if (!pattern.test(value)) {
                validationMsg.className = 'validation-message error';
                validationMsg.textContent = 'Username must be 3-50 characters and can only contain letters, numbers, and underscores';
                username.classList.add('error');
                username.classList.remove('valid');
                return false;
            } else {
                validationMsg.className = 'validation-message success';
                validationMsg.textContent = 'Username is valid';
                username.classList.remove('error');
                username.classList.add('valid');
                return true;
            }
        }
        
        function validateEmail() {
            const value = email.value;
            const validationMsg = document.getElementById('email-validation');
            const pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            
            if (value.length === 0) {
                validationMsg.className = 'validation-message error';
                validationMsg.textContent = 'Email is required';
                email.classList.add('error');
                email.classList.remove('valid');
                return false;
            } else if (!pattern.test(value)) {
                validationMsg.className = 'validation-message error';
                validationMsg.textContent = 'Please enter a valid email address';
                email.classList.add('error');
                email.classList.remove('valid');
                return false;
            } else {
                validationMsg.className = 'validation-message success';
                validationMsg.textContent = 'Email is valid';
                email.classList.remove('error');
                email.classList.add('valid');
                return true;
            }
        }
        
        function validatePhone() {
            const value = phone.value;
            const validationMsg = document.getElementById('phone-validation');
            const pattern = /^[0-9+\-\s()]{10,20}$/;
            
            if (value.length > 0 && !pattern.test(value)) {
                validationMsg.className = 'validation-message error';
                validationMsg.textContent = 'Please enter a valid phone number';
                phone.classList.add('error');
                phone.classList.remove('valid');
                return false;
            } else if (value.length > 0) {
                validationMsg.className = 'validation-message success';
                validationMsg.textContent = 'Phone number is valid';
                phone.classList.remove('error');
                phone.classList.add('valid');
                return true;
            } else {
                validationMsg.className = 'validation-message';
                validationMsg.textContent = '';
                phone.classList.remove('error', 'valid');
                return true;
            }
        }
        
        function checkPasswordStrength() {
            const value = password.value;
            const strengthBar = document.getElementById('password-strength');
            
            if (value.length === 0) {
                strengthBar.className = 'password-strength';
                return 0;
            }
            
            let strength = 0;
            
            // Length check
            if (value.length >= 6) strength += 1;
            if (value.length >= 8) strength += 1;
            
            // Character variety checks (matching your auth.php validation)
            if (/[a-z]/.test(value)) strength += 1;
            if (/[A-Z]/.test(value)) strength += 1;
            if (/[0-9]/.test(value)) strength += 1;
            
            // Normalize to 0-3 range
            if (strength <= 2) {
                strengthBar.className = 'password-strength weak';
                return 1;
            } else if (strength <= 4) {
                strengthBar.className = 'password-strength medium';
                return 2;
            } else {
                strengthBar.className = 'password-strength strong';
                return 3;
            }
        }
        
        function validatePassword() {
            const value = password.value;
            const validationMsg = document.getElementById('password-validation');
            
            if (value.length === 0) {
                validationMsg.className = 'validation-message error';
                validationMsg.textContent = 'Password is required';
                password.classList.add('error');
                password.classList.remove('valid');
                return false;
            } else if (value.length < 6) {
                validationMsg.className = 'validation-message error';
                validationMsg.textContent = 'Password must be at least 6 characters';
                password.classList.add('error');
                password.classList.remove('valid');
                return false;
            } else {
                const strength = checkPasswordStrength();
                let strengthText = '';
                if (strength === 1) strengthText = 'Weak';
                else if (strength === 2) strengthText = 'Medium';
                else if (strength === 3) strengthText = 'Strong';
                
                validationMsg.className = 'validation-message success';
                validationMsg.textContent = `Password strength: ${strengthText}`;
                password.classList.remove('error');
                password.classList.add('valid');
                return true;
            }
        }
        
        function validateConfirmPassword() {
            const value = confirmPassword.value;
            const validationMsg = document.getElementById('confirm-validation');
            
            if (value.length === 0) {
                validationMsg.className = 'validation-message error';
                validationMsg.textContent = 'Please confirm your password';
                confirmPassword.classList.add('error');
                confirmPassword.classList.remove('valid');
                return false;
            } else if (value !== password.value) {
                validationMsg.className = 'validation-message error';
                validationMsg.textContent = 'Passwords do not match';
                confirmPassword.classList.add('error');
                confirmPassword.classList.remove('valid');
                return false;
            } else {
                validationMsg.className = 'validation-message success';
                validationMsg.textContent = 'Passwords match';
                confirmPassword.classList.remove('error');
                confirmPassword.classList.add('valid');
                return true;
            }
        }
        
        function validateForm() {
            const isUsernameValid = validateUsername();
            const isEmailValid = validateEmail();
            const isPhoneValid = validatePhone();
            const isPasswordValid = validatePassword();
            const isConfirmValid = validateConfirmPassword();
            
            const isValid = isUsernameValid && isEmailValid && isPhoneValid && isPasswordValid && isConfirmValid;
            submitBtn.disabled = !isValid;
            return isValid;
        }
        
        // Add event listeners
        username.addEventListener('input', validateForm);
        email.addEventListener('input', validateForm);
        phone.addEventListener('input', validateForm);
        password.addEventListener('input', function() {
            validatePassword();
            if (confirmPassword.value.length > 0) {
                validateConfirmPassword();
            }
            validateForm();
        });
        confirmPassword.addEventListener('input', function() {
            validateConfirmPassword();
            validateForm();
        });
        
        // Form submission
        form.addEventListener('submit', function(e) {
            if (!validateForm()) {
                e.preventDefault();
                alert('Please fix all validation errors before submitting.');
            }
        });
        
        // Real-time password match indicator
        confirmPassword.addEventListener('keyup', function() {
            if (this.value.length > 0) {
                if (password.value === this.value) {
                    this.style.borderColor = '#48bb78';
                } else {
                    this.style.borderColor = '#f56565';
                }
            } else {
                this.style.borderColor = '#e0e0e0';
            }
        });
        
        password.addEventListener('keyup', function() {
            if (confirmPassword.value.length > 0) {
                if (this.value === confirmPassword.value) {
                    confirmPassword.style.borderColor = '#48bb78';
                } else {
                    confirmPassword.style.borderColor = '#f56565';
                }
            }
        });
        
        // Initial validation
        validateForm();
    </script>
</body>
</html>