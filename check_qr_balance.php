<?php
session_start();
include_once 'db_connect.php';

header('Content-Type: application/json');

if (!isset($_POST['qr_code'])) {
    echo json_encode(['success' => false, 'message' => 'No QR code provided.']);
    exit;
}

$qr_code = trim($_POST['qr_code']);

try {
    // Hanapin ang wallet sa database na 'active' na
    // Kinukuha ang ipinasa mula sa checkout.php (kahit qr_code o wallet_id ang type)
    $input_id = trim($_POST['qr_code']); 

    // Binago ang query para i-check pareho ang qr_code at wallet_id
    $stmt = $pdo->prepare("SELECT balance FROM qr_wallets WHERE (qr_code = ? OR wallet_id = ?) AND status = 'active'");
    $stmt->execute([$input_id, $input_id]);

    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($wallet) {
        echo json_encode([
            'success' => true,
            'balance' => (float)$wallet['balance']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or inactive QR Card.'
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>