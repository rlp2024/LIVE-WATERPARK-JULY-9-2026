<?php
session_start();

// Security
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

// Boss goes dashboard
if (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'boss') {
    header("Location: admin_dashboard.php");
    exit;
}

// Staff only
$staffName = isset($_SESSION['admin_fullname']) ? $_SESSION['admin_fullname'] : 'STAFF';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Select Scanner - Ajman Water Park</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #003B72; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-box { background: white; padding: 40px; border-radius: 10px; width: 100%; max-width: 420px; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.3); }
        h2 { color: #003B72; margin-top: 0; }
        .sub { color:#666; margin-bottom: 18px; font-size:0.95rem; }
        .staff { background:#f2f6ff; border:1px solid #d6e5ff; color:#003B72; padding:10px 12px; border-radius:8px; font-weight:bold; margin-bottom: 18px; }
        .btn { width: 100%; padding: 12px; background: #003B72; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 1rem; margin: 8px 0; text-decoration:none; display:block; }
        .btn:hover { background: #002855; }
        .logout { margin-top: 12px; display:inline-block; color:#ff3b3b; text-decoration:none; font-weight:bold; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>Ajman Water Park Entry Gates</h2>
        <div class="staff">Logged in as: <?php echo htmlspecialchars($staffName); ?></div>
        <div class="sub">Choose which scanner kiosk you are assigned to:</div>

        <!--<a class="btn" href="kiosk_home.php">Kiosk Main</a>-->
        <a class="btn" href="admin_verify.php" style="background: #28a745; margin-bottom: 5px;">✅ Entry Gate Scanner (IN)</a>
        <a class="btn" href="admin_exit.php" style="background: #dc3545; margin-bottom: 20px;">⛔ Exit Gate Scanner (OUT)</a>
        
        <a class="btn" href="ParkingScanner.php">Parking Scanner</a>
        <a class="btn" href="ZiplineScanner.php">Zipline Scanner</a>
        <a class="btn" href="LockerScanner.php">Locker Scanner</a>
        <a class="btn" href="HBridgeScanner.php">HBridge Scanner</a>
        <a class="btn" href="PhotoGScanner.php">PhotoG Scanner</a>
        <a class="btn" href="CMealScanner.php">CMeal Scanner</a>

        <a class="logout" href="admin_logout.php">Logout</a>
    </div>
</body>
</html>
