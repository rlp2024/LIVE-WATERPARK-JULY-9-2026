<?php
session_start();

// Force fresh load (no cache / no bfcache)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Always keep kiosk mode on (landing page)
$_SESSION['kiosk_mode'] = true;

// Clear add-ons / topup session state so Home is always "fresh"
unset($_SESSION['last_step']);
unset($_SESSION['topup_for_booking_id']);
unset($_SESSION['booking_date']);
unset($_SESSION['guest']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Kiosk - Ajman Water Park</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&display=swap" rel="stylesheet">
    <!-- Favicon (recommended: .ico for broad support) -->
    <link rel="shortcut icon" href="/awpfav.png" type="image/x-icon">

    <!--iOS PWA Meta Tags
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    iOS PWA Meta Tags-->
    
    <style>
        * {
            box-sizing: border-box;
        }

        body { 
            font-family: 'Poppins', sans-serif; 
            background: #32BFB6; 
            color: white; 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            min-height: 100vh; 
            margin: 0; 
            text-align: center; 
            padding: 20px;
        }

        .kiosk-container { 
            width: 100%; 
            max-width: 900px; 
            position: relative; 
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        h1 {
            font-size: clamp(2rem, 5vw, 3.5rem);
            margin-bottom: 20px;
            text-transform: uppercase;
            letter-spacing: 4px;
            font-weight: 800;
            text-shadow: 0 4px 10px rgba(0,0,0,0.25);
        }

        .logo {
            width: 50%;
            max-width: 300px;
            min-width: 150px;
            height: auto;
            display: block;
            margin: 10px auto 30px auto;
        }
        
        .btn-group { 
            display: flex; 
            gap: 30px; 
            justify-content: center; 
            flex-wrap: wrap; 
            width: 100%;
        }
        
        .kiosk-btn {
            background: white; 
            color: #373737ff; 
            border: none;
            /* Responsive layout */
            flex: 1; 
            min-width: 250px; 
            max-width: 350px; 
            aspect-ratio: 1 / 1; 
            
            border-radius: 20px; 
            font-size: clamp(2rem, 2.5vw, 4rem); 
            font-weight: bold;
            cursor: pointer; 
            transition: 0.3s;
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            justify-content: center;
            text-decoration: none; 
            box-shadow: 0 7px 0 rgba(0, 0, 0, .5);
        }

        .kiosk-btn:active { transform: translateY(10px); box-shadow: none; }
        
        .kiosk-btn i { 
            font-size: clamp(6rem, 6vw, 5rem); 
            margin-bottom: 20px; 
        }
        
        .kiosk-btn:hover { background: #00d5ea; }

        .btn-scan { background: #FFD84D; color: white; box-shadow: 0 7px 0 rgba(0, 0, 0, .5); }
        .btn-scan:hover { background: #00d5ea; }

      .btn-order { background: #f1cf96ff; color: white; box-shadow: 0 7px 0 rgba(0, 0, 0, .5); }
        .btn-order:hover { background: #00d5ea; }

        /* Idagdag ito para sa Top-up Button */
        .btn-topup { background: #FF8A65; color: white; box-shadow: 0 7px 0 rgba(0, 0, 0, .5); }
        .btn-topup:hover { background: #00d5ea; }

        /* Mobile Adjustment */
        @media (max-width: 600px) {
            .btn-group {
                flex-direction: column;
                align-items: center;
            }
            .kiosk-btn {
                width: 100%;
                max-width: 100%;
            }
        }

        
    </style>

<!--<script defer src="/kiosk_fullscreen.js"></script>-->

</head>
<body>


    <div class="kiosk-container">
        
        <h1>Welcome</h1>

        <img src="Images/kiosk-logo.webp" class="logo" alt="Ajman Water Park">
            
      <div class="btn-group">
            <a href="kiosk_book.php" class="kiosk-btn btn-buy">
                <i class="fas fa-ticket-alt kiosk-btn__icon"></i>
                <span class="kiosk-btn__text">BUY TICKET</span>
            </a>

            <a href="kiosk buy add ons only.php" class="kiosk-btn btn-scan" style="color:#373737ff;">
                <i class="fas fa-qrcode kiosk-btn__icon"></i>
                <span class="kiosk-btn__text">BUY ADD-ONS</span>
            </a>

            <a href="topup.php" class="kiosk-btn btn-topup" style="color:#373737ff;">
                <i class="fas fa-wallet kiosk-btn__icon"></i>
                <span class="kiosk-btn__text">TOP-UP WALLET</span>
            </a>

       <!--     <a href="fnb2/index.php" class="kiosk-btn btn-order" style="color:#373737ff;">
                <i class="fas fa-utensils kiosk-btn__icon" aria-hidden="true"></i>
                <span class="kiosk-btn__text">ORDER FOOD</span>
            </a>-->
        </div>
        
        <p style="margin-top: 50px; opacity: 0.7;">Please ask our staff for assistance.</p>
    </div>

<!--?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/kiosk_fullscreen_include.php'; ?>-->

</body>
</html>