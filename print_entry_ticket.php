<?php
session_start();
include_once 'db_connect.php';

// Security
if (!isset($_SESSION['admin_logged_in'])) { header("Location: admin_login.php"); exit; }

$booking_id = $_GET['booking_id'];

// Get Booking Details
$stmt = $pdo->prepare("SELECT * FROM bookings WHERE booking_id = ?");
$stmt->execute([$booking_id]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) { die("Ticket not found."); }

// Get Items (Para alam ni Guard ilan ang papasok/bibigyan ng wristband)
$stmt_items = $pdo->prepare("
    SELECT p.name, bi.quantity 
    FROM booking_items bi 
    JOIN products p ON bi.product_id = p.product_id 
    WHERE bi.booking_id = ?
");
$stmt_items->execute([$booking_id]);
$items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

// Calculate Total Pax (Optional)
$total_pax = 0;
foreach($items as $i) $total_pax += $i['quantity'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Entry Pass #<?php echo $booking_id; ?></title>
    <style>
        body { font-family: 'Arial', sans-serif; background: #eee; display: flex; justify-content: center; padding-top: 20px; margin: 0; }
        
        /* THERMAL TICKET STYLE */
        .ticket {
            width: 80mm;
            background: white;
            padding: 15px;
            box-shadow: 0 0 5px rgba(0,0,0,0.1);
            text-align: center;
        }

        .header-title { font-size: 1.5rem; font-weight: 900; margin: 0; color: #000; text-transform: uppercase; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 10px; }
        .sub-header { font-size: 0.8rem; text-transform: uppercase; margin-bottom: 5px; }
        
        .big-ref { font-size: 1.8rem; font-weight: bold; margin: 10px 0; font-family: 'Courier New', monospace; letter-spacing: 2px; }
        
        .guest-name { font-size: 1.1rem; font-weight: bold; margin-bottom: 5px; text-transform: uppercase; }
        .date-time { font-size: 0.75rem; color: #555; margin-bottom: 15px; }

        /* ITEM LIST (LEFT ALIGNED) */
        .items-box { border: 1px dashed #000; padding: 10px; text-align: left; margin-bottom: 15px; }
        .item-row { display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 5px; }
        .total-pax { border-top: 1px solid #000; margin-top: 5px; padding-top: 5px; font-weight: bold; text-align: right; }

        .verified-by { font-size: 0.7rem; color: #000; margin-top: 20px; border-top: 1px solid #ddd; padding-top: 5px; }

        /* BUTTONS */
        .actions { margin-top: 20px; }
        .btn { padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 0.9rem; cursor: pointer; border: none; display: inline-block; }
        .btn-back { background: #333; color: white; }

        @media print {
            body { background: white; padding: 0; }
            .ticket { box-shadow: none; width: 100%; padding: 0; margin: 0; }
            .actions { display: none !important; }
            @page { margin: 0; }
        }
    </style>
</head>
<body>

    <div class="ticket">
        
        <div class="header-title">ENTRY PASS</div>
        <div class="sub-header">Ajman Water Park</div>
        
        <div class="big-ref">#<?php echo str_pad($booking['booking_id'], 6, '0', STR_PAD_LEFT); ?></div>
        
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?php echo $booking_id; ?>" style="width: 80px; margin-bottom: 10px;">

        <div class="guest-name"><?php echo htmlspecialchars($booking['customer_name']); ?></div>
        <div class="date-time">
            Entry: <?php echo date("d M Y, h:i A", strtotime($booking['redeemed_at'])); ?>
        </div>

        <div class="items-box">
            <div style="font-size:0.7rem; font-weight:bold; margin-bottom:5px;">ADMIT:</div>
            <?php foreach ($items as $item): ?>
                <div class="item-row">
                    <span><?php echo $item['name']; ?></span>
                    <strong>x<?php echo $item['quantity']; ?></strong>
                </div>
            <?php endforeach; ?>
            
            <div class="total-pax">
                TOTAL GUESTS: <?php echo $total_pax; ?>
            </div>
        </div>

        <div class="verified-by">
            Verified & Scanned by:<br>
            <strong><?php echo htmlspecialchars($booking['redeemed_by']); ?></strong>
        </div>

        <div class="actions">
            <a href="admin_verify.php" class="btn btn-back">Scan Next Guest</a>
        </div>

    </div>

    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        }
    </script>

</body>
</html>