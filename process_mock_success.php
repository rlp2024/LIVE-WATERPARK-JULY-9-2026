<?php
// process_mock_success.php
session_start();
include_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['booking_id'])) {
    header('Location: index.php'); exit;
}

$booking_id = $_POST['booking_id'];

try {
    // 1. Update DB to PAID
    $stmt = $pdo->prepare("UPDATE bookings SET payment_status = 'paid', payment_method = 'tabby', paymongo_checkout_id = :txn WHERE booking_id = :id");
    $stmt->execute([':txn' => 'SIMULATED_' . uniqid(), ':id' => $booking_id]);

    // 2. Redirect to Success Page
    // IMPORTANT: Wag mag-unset ng session dito para hindi bumalik sa book.php
    header("Location: success.php?booking_id=" . $booking_id);
    exit;

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>