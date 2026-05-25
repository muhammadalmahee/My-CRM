<?php
// forgot_password.php
include 'config.php'; 

$success_msg = "";
$error_msg = "";

if (isset($_POST['reset_request'])) {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    
    // এখানে আপনার ইমেইল চেক করার লজিক দিতে পারেন
    // আপাতত সাকসেস মেসেজ দেখানোর জন্য:
    $success_msg = "If this email is registered, you will receive a reset link shortly.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Systellio CRM</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: #f9fafb;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .login-card {
            background-color: #ffffff;
            width: 100%;
            max-width: 420px;
            padding: 40px 35px;
            border-radius: 12px;
            border: 1px solid rgba(229, 231, 235, 0.8);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
        }

        .logo-container {
            text-align: center;
            margin-bottom: 0px; 
        }

        .logo-container img {
            max-width: 110px;
            height: auto;
        }

        .header-title {
            font-size: 24px;
            color: #111827;
            font-weight: 800;
            text-align: center;
            margin-bottom: 30px; 
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .input-group {
            margin-bottom: 25px;
        }

        label {
            font-size: 13px;
            font-weight: 700;
            color: #374151;
            display: block;
            margin-bottom: 8px;
        }

        input[type="email"] {
            width: 100%;
            padding: 14px 16px;
            background-color: #f3f4f6; 
            border: 1px solid transparent;
            border-radius: 6px;
            font-size: 14px;
            color: #1f2937;
            outline: none;
            transition: all 0.3s ease;
        }

        input:focus {
            background-color: #ffffff;
            border: 1px solid #d1d5db;
            box-shadow: 0 0 0 3px rgba(209, 213, 219, 0.2);
        }

        .login-btn {
            width: 100%;
            background-color: #0f172a;
            color: #ffffff;
            padding: 15px;
            border: none;
            border-radius: 6px;
            font-size: 15px; 
            font-weight: 700;
            cursor: pointer;
            transition: background-color 0.3s, transform 0.1s;
            margin-bottom: 20px;
        }

        .login-btn:hover {
            background-color: #1e293b;
        }

        .back-link {
            display: block;
            text-align: center;
            font-size: 13px; 
            font-weight: 600;
            color: #374151;
            text-decoration: none;
            transition: color 0.3s;
        }

        .back-link:hover {
            color: #000000;
            text-decoration: underline;
        }

        .success {
            color: #059669;
            background-color: #ecfdf5;
            border: 1px solid #34d399;
            padding: 12px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
            text-align: center;
            border-radius: 6px;
        }
        
        .footer {
            margin-top: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .footer .line {
            height: 1px;
            width: 50px;
            background-color: #e5e7eb;
        }

        .footer-text {
            font-size: 11px; 
            font-weight: 700;
            color: #6b7280;
        }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="logo-container">
            <img src="img/logo1.png" alt="Systellio Logo">
        </div>

        <h1 class="header-title">Forgot Password</h1>

        <?php if($success_msg != "") { echo "<div class='success'><i class='fa-solid fa-circle-check'></i> $success_msg</div>"; } ?>

        <form action="" method="POST">
            <div class="input-group">
                <label>Registered Email Address</label>
                <input type="email" name="email" placeholder="Enter your email" required>
            </div>

            <button type="submit" name="reset_request" class="login-btn">
                Send Reset Link
            </button>

            <a href="index.php" class="back-link">
                <i class="fa-solid fa-arrow-left" style="font-size: 11px; margin-right: 5px;"></i> Back to Login
            </a>
        </form>

        <div class="footer">
            <div class="line"></div>
            <div class="footer-text">By Peer Solution With <span style="color: #ef4444; font-size: 13px;">❤️</span></div>
            <div class="line"></div>
        </div>
    </div>

</body>
</html>