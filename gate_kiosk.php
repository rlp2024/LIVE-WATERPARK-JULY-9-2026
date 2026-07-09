<?php
session_start();
include_once 'db_connect.php';

// Security Check
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: admin_login.php");
    exit;
}

$status = "standby";
$msg = "SCAN ENTRY PASS";
$guest_name = "";
$sub_msg = "Please position QR code inside the box";
$verifier_info = "";

// --- PROCESS SCAN (VERIFICATION ONLY) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['qr_data'])) {
    $id = trim($_POST['qr_data']);
    
    // Check DB
    $stmt = $pdo->prepare("SELECT * FROM bookings WHERE booking_id = ?");
    $stmt->execute([$id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        $status = "error";
        $msg = "INVALID TICKET";
        $sub_msg = "Ticket ID not found.";
    } 
    else if ($booking['payment_status'] != 'paid') {
        $status = "error";
        $msg = "NOT PAID";
        $sub_msg = "Please proceed to payment counter.";
    }
    else if ($booking['is_redeemed'] == 0) {
        $status = "warning";
        $msg = "NOT YET ADMITTED";
        $guest_name = $booking['customer_name'];
        $sub_msg = "Please present this ticket to the Cashier/Staff for admission first.";
    }
    else {
        $status = "success";
        $msg = "✔ ENTRY VERIFIED";
        $guest_name = $booking['customer_name'];
        $verifier_info = "Authorized By: <strong>" . htmlspecialchars($booking['redeemed_by']) . "</strong><br>";
        $verifier_info .= "Time: " . date("h:i A", strtotime($booking['redeemed_at']));
        $sub_msg = "Enjoy your visit!";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gate Verification Kiosk</title>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { margin: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #1a1a1a; color: white; overflow: hidden; height: 100vh; display: flex; flex-direction: column; }
        
        /* CONTAINER NG CAMERA (CENTERED) */
        .scanner-wrapper {
            position: relative;
            width: 100%;
            height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: radial-gradient(circle, #2c3e50 0%, #000000 100%); /* Nice gradient background */
        }

        /* ANG CAMERA BOX MISMO (SAKTUHAN SIZE) */
        #reader { 
            width: 100%;
            max-width: 500px; /* Ito ang naglilimit ng size para di full screen */
            background: black;
            border-radius: 20px; /* Rounded corners */
            box-shadow: 0 10px 30px rgba(0,0,0,0.5); /* Shadow effect */
            border: 5px solid #333;
            overflow: hidden;
        }
        
        /* HEADER SA TAAS NG CAMERA */
        .scan-instruction {
            margin-bottom: 20px;
            text-align: center;
            z-index: 10;
            background: transparent;
        }
        .scan-instruction h2 { margin: 0; color: white; font-size: 1.8rem; text-transform: uppercase; letter-spacing: 2px; text-shadow: 0 2px 4px rgba(0,0,0,0.8); }

        /* OVERLAY MESSAGE (POPUP RESULT) */
        .result-overlay {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            z-index: 999;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            background: rgba(0,0,0,0.95);
            text-align: center;
            animation: fadeIn 0.3s;
            padding: 20px; box-sizing: border-box;
        }

        .icon-box { font-size: 6rem; margin-bottom: 20px; animation: popIn 0.4s; }
        .main-text { font-size: 3rem; font-weight: 900; text-transform: uppercase; margin: 0; letter-spacing: 1px; line-height: 1.1; }
        .guest-name { font-size: 1.8rem; color: #fff; margin: 15px 0; font-weight: bold; border-bottom: 2px solid rgba(255,255,255,0.3); padding-bottom: 10px; display: inline-block; }
        .verifier-box { background: rgba(255,255,255,0.1); padding: 15px 30px; border-radius: 10px; margin-top: 10px; font-size: 1.2rem; }
        .sub-text { font-size: 1.1rem; color: #aaa; margin-top: 15px; }

        /* COLORS */
        .bg-success { color: #2ecc71; border-color: #2ecc71; }
        .bg-error { color: #e74c3c; }
        .bg-warning { color: #f39c12; }

        /* HIDE UGLY BUTTONS OF LIBRARY IF NEEDED */
        #reader__dashboard_section_csr button { display: none; } 

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes popIn { from { transform: scale(0.5); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    </style>
</head>
<body>

    <div class="scanner-wrapper">
        
        <?php if ($status === 'standby'): ?>
        <div class="scan-instruction">
            <h2><i class="fas fa-qrcode"></i> Scan Entry Pass</h2>
            <div style="font-size:1rem; color:#ddd; margin-top:5px;">Place QR Code within the frame</div>
        </div>
        <?php endif; ?>

        <div id="reader"></div>

        <?php if ($status !== 'standby'): ?>
            <div class="result-overlay">
                
                <?php if ($status == 'success'): ?>
                    <div class="icon-box bg-success"><i class="fas fa-user-check"></i></div>
                    <div class="main-text bg-success"><?php echo $msg; ?></div>
                    <div class="guest-name"><?php echo htmlspecialchars($guest_name); ?></div>
                    
                    <div class="verifier-box">
                        <?php echo $verifier_info; ?>
                    </div>
                    
                    <div class="sub-text"><?php echo $sub_msg; ?></div>
                    <audio autoplay><source src="assets/success.mp3" type="audio/mpeg"></audio>

                <?php elseif ($status == 'warning'): ?>
                    <div class="icon-box bg-warning"><i class="fas fa-hand-paper"></i></div>
                    <div class="main-text bg-warning"><?php echo $msg; ?></div>
                    <div class="guest-name"><?php echo htmlspecialchars($guest_name); ?></div>
                    <div class="sub-text" style="color:#fff; font-weight:bold; background:#c0392b; padding:10px; border-radius:5px;">
                        <?php echo $sub_msg; ?>
                    </div>
                    <audio autoplay><source src="assets/error.mp3" type="audio/mpeg"></audio>

                <?php else: ?>
                    <div class="icon-box bg-error"><i class="fas fa-times-circle"></i></div>
                    <div class="main-text bg-error"><?php echo $msg; ?></div>
                    <div class="sub-text"><?php echo $sub_msg; ?></div>
                    <audio autoplay><source src="assets/error.mp3" type="audio/mpeg"></audio>
                <?php endif; ?>
            </div>

            <script>
                setTimeout(function() {
                    window.location.href = "gate_kiosk.php";
                }, 4000);
            </script>
        <?php endif; ?>
    </div>

    <form id="scanForm" method="POST" style="display:none;">
        <input type="text" name="qr_data" id="qr_input">
    </form>

    <script>
        const qrboxFunction = function(viewfinderWidth, viewfinderHeight) {
            // Mas maliit na box sa loob ng camera para saktong scan
            let minEdgePercentage = 0.8; 
            let minEdgeSize = Math.min(viewfinderWidth, viewfinderHeight);
            let qrboxSize = Math.floor(minEdgeSize * minEdgePercentage);
            return { width: qrboxSize, height: qrboxSize };
        }

        function onScanSuccess(decodedText, decodedResult) {
            html5QrcodeScanner.clear();
            document.getElementById('qr_input').value = decodedText;
            document.getElementById('scanForm').submit();
        }

        let html5QrcodeScanner = new Html5QrcodeScanner(
            "reader", 
            { 
                fps: 20, 
                qrbox: qrboxFunction,
                aspectRatio: 1.0, 
                videoConstraints: {
                    facingMode: "environment", 
                    focusMode: "continuous"
                }
            },
            false
        );
        html5QrcodeScanner.render(onScanSuccess);
    </script>

</body>
</html>