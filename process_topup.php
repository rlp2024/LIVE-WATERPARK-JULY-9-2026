<?php
// process_topup.php
session_start();
include_once 'db_connect.php';

date_default_timezone_set('Asia/Dubai');

// Security Check
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['booking_id'])) {
    header("Location: admin_addon_scanner_base.php");
    exit;
}

$bookingId = (int)$_POST['booking_id'];
$quantities = $_POST['qty'] ?? []; // Array ng [product_id => quantity]
$paymentMethod = $_POST['payment_method'] ?? 'card';
$adminName = $_SESSION['admin_fullname'] ?? 'System';

// Filter items na may quantity > 0
$cartItems = [];
foreach ($quantities as $pid => $qty) {
    if ((int)$qty > 0) {
        $cartItems[$pid] = (int)$qty;
    }
}

if (empty($cartItems)) {
    // Walang laman, balik lang
    header("Location: admin_addon_scanner_base.php?booking_id=$bookingId");
    exit;
}

try {
    $pdo->beginTransaction();

    $totalAmount = 0;
    $itemsSummary = [];

    // Prepared Statements
    $stmtPrice  = $pdo->prepare("SELECT price FROM products WHERE product_id = ?");
    $stmtItem   = $pdo->prepare("INSERT INTO booking_items (booking_id, product_id, quantity, price_per_item) VALUES (?, ?, ?, ?)");
    $stmtRedeem = $pdo->prepare("
        INSERT INTO addon_redemptions (booking_id, product_id, unique_code, quantity_total, quantity_used, status) 
        VALUES (?, ?, ?, ?, 0, 'unused') 
        ON DUPLICATE KEY UPDATE quantity_total = quantity_total + VALUES(quantity_total), status = IF(quantity_total > quantity_used, 'unused', status)
    ");

    foreach ($cartItems as $pid => $qty) {
        // 1. Get Real Price from DB (Security)
        $stmtPrice->execute([$pid]);
        $price = $stmtPrice->fetchColumn();
        
        if ($price !== false) {
            $subtotal = $price * $qty;
            $totalAmount += $subtotal;
            $itemsSummary[] = "$pid (x$qty)";

            // 2. Insert to booking_items
            $stmtItem->execute([$bookingId, $pid, $qty, $price]);

            // 3. Update addon_redemptions (Scanner Permission)
            $uniqueCode = $bookingId . '-' . $pid;
            $stmtRedeem->execute([$bookingId, $pid, $uniqueCode, $qty]);
        }
    }

    // 4. Log Transaction
    if ($totalAmount > 0) {
        $summaryStr = implode(", ", $itemsSummary);
        $stmtLog = $pdo->prepare("INSERT INTO addon_purchase_logs (booking_id, action, payment_status, payment_method, total_amount, items_summary, created_by) VALUES (?, 'TOPUP', 'paid', ?, ?, ?, ?)");
        $stmtLog->execute([$bookingId, $paymentMethod, $totalAmount, $summaryStr, $adminName]);

        // 5. Update Main Booking Total
        $stmtUpdateMain = $pdo->prepare("UPDATE bookings SET total_amount = total_amount + ? WHERE booking_id = ?");
        $stmtUpdateMain->execute([$totalAmount, $bookingId]);
    }

    $pdo->commit();

    // Success redirect
    header("Location: admin_addon_scanner_base.php?booking_id=$bookingId&msg=topup_success");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    die("Error processing top-up: " . $e->getMessage());
}
?>