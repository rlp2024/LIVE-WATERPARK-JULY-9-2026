<?php
// checkout_addon.php
session_start();

// Security Check
if (empty($_SESSION['topup_cart']) || empty($_SESSION['topup_booking_id'])) {
    header("Location: admin_addon_scanner_base.php");
    exit;
}

$cart = $_SESSION['topup_cart'];
$bookingId = $_SESSION['topup_booking_id'];
$customerName = $_SESSION['topup_customer_name'] ?? 'Guest';

$total = 0;
foreach($cart as $item) $total += $item['subtotal'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Add-on Purchase</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: #0b1220; color: #eaf0ff; display:flex; justify-content:center; align-items:center; min-height:100vh; margin:0; }
        .box { background: #111827; padding: 30px; border-radius: 20px; width: 100%; max-width: 420px; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 20px 50px rgba(0,0,0,0.5); }
        .row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 0.95rem; }
        .total { font-size: 1.8rem; font-weight: 900; color: #22c55e; margin-top: 20px; text-align: right; }
        .btn { width: 100%; padding: 18px; margin-top: 12px; font-weight: 800; border-radius: 12px; cursor: pointer; border: none; font-size: 1rem; transition: 0.2s; }
        .btn-pay { background: linear-gradient(135deg, #22c55e, #16a34a); color: #fff; box-shadow: 0 4px 15px rgba(34, 197, 94, 0.4); }
        .btn-pay:hover { transform: translateY(-2px); }
        .btn-cancel { background: transparent; color: #9ca3af; border: 1px solid rgba(255,255,255,0.1); }
        .radio-label { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; padding: 15px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; cursor: pointer; }
        .radio-label:hover { background: rgba(255,255,255,0.06); }
        h2 { margin: 0 0 5px; color: white; }
        p { margin: 0 0 20px; color: #9ca3af; font-size: 0.9rem; }
    </style>
</head>
<body>
    <div class="box">
        <h2>Top-up Order #<?php echo $bookingId; ?></h2>
        <p><?php echo htmlspecialchars($customerName); ?></p>
        
        <div style="margin-bottom:20px;">
            <?php foreach($cart as $item): ?>
            <div class="row">
                <div style="color:#d1d5db;">
                    <span style="color:white; font-weight:bold;"><?php echo htmlspecialchars($item['name']); ?></span> 
                    <span style="opacity:0.6;">(x<?php echo $item['quantity']; ?>)</span>
                </div>
                <div><?php echo number_format($item['subtotal'], 2); ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="total">AED <?php echo number_format($total, 2); ?></div>

        <form action="process_topup.php" method="POST" style="margin-top:25px;">
            <label class="radio-label">
                <input type="radio" name="payment_method" value="card" checked> 
                <i class="fa-brands fa-cc-visa fa-lg" style="color:#fff;"></i> Card / Tabby / Apple Pay
            </label>
            <label class="radio-label">
                <input type="radio" name="payment_method" value="cash"> 
                <i class="fa-solid fa-money-bill-wave" style="color:#22c55e;"></i> Cash
            </label>

            <button type="submit" class="btn btn-pay">CONFIRM PAYMENT</button>
            <a href="admin_addon_scanner_base.php?booking_id=<?php echo $bookingId; ?>" class="btn btn-cancel" style="display:block; text-align:center; text-decoration:none;">CANCEL</a>
        </form>
    </div>
</body>
</html>