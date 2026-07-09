<?php
session_start();
// Security check: Siguraduhin na may booking ID
if (!isset($_GET['booking_id']) || !isset($_GET['amount'])) {
    header("Location: index.php");
    exit;
}

$booking_id = $_GET['booking_id'];
$amount = $_GET['amount'];
$installment = $amount / 4;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tabby | Checkout</title>
    <style>
        body { font-family: 'Helvetica Neue', Arial, sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .tabby-card { background: white; width: 100%; max-width: 400px; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); text-align: center; }
        .tabby-logo { height: 30px; margin-bottom: 20px; }
        .amount-box { background: #3BFEB8; color: black; padding: 15px; border-radius: 8px; margin: 20px 0; }
        .pay-btn { background: black; color: white; border: none; padding: 15px; width: 100%; border-radius: 50px; font-weight: bold; cursor: pointer; font-size: 1rem; transition: 0.3s; }
        .pay-btn:hover { background: #333; }
        .steps { display: flex; justify-content: space-between; margin-bottom: 20px; font-size: 0.8rem; color: #555; }
        .step { text-align: center; width: 25%; }
        .dot { height: 10px; width: 10px; background: #ddd; border-radius: 50%; margin: 0 auto 5px; }
        .dot.active { background: #3BFEB8; border: 2px solid black; }
    </style>
</head>
<body>
    <div class="tabby-card">
                                <img src="Images/tabby-logo-1.png" alt="Tabby" style="height:25px; width:auto;">
        <h2>Pay in 4 interest-free payments</h2>
        
        <div class="amount-box">
            <small>TOTAL AMOUNT</small><br>
            <strong style="font-size: 1.5rem;">AED <?php echo number_format($amount, 2); ?></strong>
        </div>

        <div class="steps">
            <div class="step"><div class="dot active"></div>Today<br><strong><?php echo number_format($installment, 0); ?></strong></div>
            <div class="step"><div class="dot"></div>1 Mo<br><?php echo number_format($installment, 0); ?></div>
            <div class="step"><div class="dot"></div>2 Mo<br><?php echo number_format($installment, 0); ?></div>
            <div class="step"><div class="dot"></div>3 Mo<br><?php echo number_format($installment, 0); ?></div>
        </div>

        <p style="font-size: 0.9rem; color: #666; margin-bottom: 20px;">Secure payment via Tabby. No interest, no fees.</p>
        
        <form action="process_mock_success.php" method="POST">
            <input type="hidden" name="booking_id" value="<?php echo $booking_id; ?>">
            <button type="submit" class="pay-btn">PAY AED <?php echo number_format($installment, 2); ?></button>
        </form>
    </div>
</body>
</html>