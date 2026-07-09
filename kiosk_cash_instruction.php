<?php
$booking_id = $_GET['booking_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Pay at Counter</title>
    <link rel="stylesheet" href="booking-style.css">
    <style>
        body { text-align: center; padding: 50px; background: #f4f8fb; }
        .card { background: white; padding: 40px; border-radius: 20px; max-width: 500px; margin: 0 auto; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .number { font-size: 4rem; font-weight: bold; color: #003B72; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Please Proceed to Cashier</h1>
        <p>Please Take a Photo</p>
        <p>Show this number to the staff to complete your payment.</p>
        
        <div class="number">#<?php echo str_pad($booking_id, 6, '0', STR_PAD_LEFT); ?></div>
        
        <p>Your ticket will be printed after payment.</p>
        
        <br>
        <a href="kiosk_home.php" class="ww-next-btn">Back to Home</a>
    </div>
</body>
</html>