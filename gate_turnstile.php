<?php
// gate_turnstile.php - PRO KIOSK STYLE
include_once 'db_connect.php';
date_default_timezone_set('Asia/Dubai');

$msg = "SCAN TICKET";
$msgType = "neutral"; // neutral, success, error, used
$guestInfo = "";
$subInfo = "Please place QR code in the window";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qr'])) {
    $qr = trim($_POST['qr']);

    try {
        $stmt = $pdo->prepare("
            SELECT t.*, b.payment_status, b.customer_name, b.visit_date, b.expiry_date
            FROM ticket_instances t
            JOIN bookings b ON t.booking_id = b.booking_id
            WHERE t.ticket_code = ? LIMIT 1
        ");
        $stmt->execute([$qr]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            $msg = "INVALID TICKET";
            $msgType = "error";
            $subInfo = "QR Code not found.";
        } 
        elseif ($ticket['payment_status'] !== 'paid') {
            $msg = "NOT PAID";
            $msgType = "error";
            $subInfo = "Payment is pending.";
        } 
        elseif ($ticket['status'] === 'used') {
            $msg = "ALREADY USED";
            $msgType = "used";
            $guestInfo = $ticket['customer_name'];
            $subInfo = "Entered: " . date('h:i A', strtotime($ticket['entry_time']));
        } 
        else {
            $today = date('Y-m-d');
            $isValidDate = false;
            $dateMsg = "";

            if (!empty($ticket['expiry_date'])) {
                if ($ticket['expiry_date'] >= $today) {
                    $isValidDate = true;
                } else {
                    $dateMsg = "Expired: " . $ticket['expiry_date'];
                }
            } else {
                if ($ticket['visit_date'] == $today) {
                    $isValidDate = true;
                } else {
                    $dateMsg = "Valid Only On: " . $ticket['visit_date'];
                }
            }

            if (!$isValidDate) {
                $msg = "WRONG DATE";
                $msgType = "error";
                $subInfo = $dateMsg;
            } else {
                // SUCCESS
                $upd = $pdo->prepare("UPDATE ticket_instances SET status='used', entry_time=NOW() WHERE ticket_id=?");
                $upd->execute([$ticket['ticket_id']]);

                $msg = "ACCESS GRANTED";
                $msgType = "success";
                $guestInfo = $ticket['ticket_type'];
                $subInfo = "Welcome, " . $ticket['customer_name'];
            }
        }

    } catch (Exception $e) {
        $msg = "SYSTEM ERROR";
        $msgType = "error";
        $subInfo = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gate Turnstile</title>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <style>
        body { 
            margin: 0; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            height: 100vh; 
            display: flex; 
            flex-direction: column; 
            background: #111; 
            color: white; 
            overflow: hidden; 
        }

        /* --- HEADER STATUS AREA --- */
        .status-header {
            padding: 30px 20px;
            text-align: center;
            background: #222;
            border-bottom: 2px solid #444;
            transition: background 0.3s ease;
            min-height: 25vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        h1 { font-size: 3.5rem; margin: 0; font-weight: 900; letter-spacing: 2px; line-height: 1; }
        .guest-name { font-size: 1.8rem; margin-top: 10px; font-weight: 600; color: #fff; }
        .sub-text { font-size: 1.2rem; margin-top: 5px; color: #888; }

        /* --- CAMERA CONTAINER (CENTERED BOX) --- */
        .camera-wrapper {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #000;
            position: relative;
        }

        /* This forces the camera to look like a Kiosk Window */
        #reader { 
            width: 500px !important;  /* Fixed Width */
            height: 400px !important; /* Fixed Height */
            border: 5px solid rgba(255,255,255,0.2) !important;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 0 50px rgba(0,0,0,0.8);
            background: #000;
        }

        /* FIX VIDEO STRETCHING inside the box */
        #reader video { 
            width: 100% !important; 
            height: 100% !important; 
            object-fit: cover !important; 
            border-radius: 15px;
        }

        /* Scan Line Animation (Optional Cool Effect) */
        .scan-line {
            position: absolute;
            width: 500px;
            height: 2px;
            background: rgba(0, 255, 0, 0.8);
            box-shadow: 0 0 10px rgba(0, 255, 0, 0.8);
            top: 50%;
            animation: scan 2s infinite linear;
            pointer-events: none;
            z-index: 10;
        }
        @keyframes scan {
            0% { transform: translateY(-180px); opacity: 0; }
            50% { opacity: 1; }
            100% { transform: translateY(180px); opacity: 0; }
        }

        /* STATUS COLORS */
        .neutral { background: #1a1a1a; }
        .neutral h1 { color: #fff; }
        
        .success { background: #198754; } 
        .success h1 { color: #fff; }
        .success .sub-text { color: #e9ecef; }

        .error { background: #dc3545; }
        .error h1 { color: #fff; }
        .error .sub-text { color: #f8d7da; }

        .used { background: #ffc107; }
        .used h1 { color: #000; }
        .used .guest-name { color: #000; }
        .used .sub-text { color: #333; }

        /* Hide Library UI Elements */
        #reader__dashboard_section_csr, 
        #reader__dashboard_section_swaplink, 
        #reader__header_message { display: none !important; }
    </style>
</head>
<body>

    <div class="status-header <?php echo $msgType; ?>">
        <h1><?php echo htmlspecialchars($msg); ?></h1>
        <?php if($guestInfo): ?>
            <div class="guest-name"><?php echo htmlspecialchars($guestInfo); ?></div>
        <?php endif; ?>
        <div class="sub-text"><?php echo htmlspecialchars($subInfo); ?></div>
    </div>

    <div class="camera-wrapper">
        <div id="reader"></div>
        <div class="scan-line"></div> </div>

    <form method="POST" id="scanForm" style="display:none;">
        <input type="text" name="qr" id="qrInput">
    </form>

    <script>
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        function beep(freq = 520, duration = 200, type = "sine") {
            if (audioCtx.state === 'suspended') audioCtx.resume();
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = type;
            osc.frequency.value = freq;
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start();
            setTimeout(() => osc.stop(), duration);
        }

        function onScan(text) {
            html5QrcodeScanner.pause(); 
            document.getElementById('qrInput').value = text;
            document.getElementById('scanForm').submit();
        }

        // Initialize Camera
        let html5QrcodeScanner = new Html5QrcodeScanner(
            "reader", 
            { 
                fps: 10, 
                qrbox: { width: 300, height: 300 } // Scanning Box Size
            }, 
            false
        );
        
        html5QrcodeScanner.render(onScan);

        // Auto Reset
        <?php if($msgType !== 'neutral'): ?>
            <?php if($msgType === 'success'): ?>
                beep(1000, 100, 'square'); 
            <?php else: ?>
                beep(200, 400, 'sawtooth');
            <?php endif; ?>
            
            setTimeout(() => { window.location.href = 'gate_turnstile.php'; }, 3000);
        <?php endif; ?>
    </script>
</body>
</html>