<?php
// Huwag isama ang header.php or footer.php.
include_once 'db_connect.php';
require_once 'vendor/autoload.php';

// Ilagay ang iyong Stripe API Secret Key
\Stripe\Stripe::setApiKey('sk_test_YOUR_STRIPE_SECRET_KEY_HERE');

// Ilagay ang iyong Webhook Signing Secret galing sa Stripe Dashboard
$endpoint_secret = 'whsec_YOUR_WEBHOOK_SECRET_HERE';

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
$event = null;

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload, $sig_header, $endpoint_secret
    );
} catch(\UnexpectedValueException $e) {
    // Invalid payload
    http_response_code(400);
    exit();
} catch(\Stripe\Exception\SignatureVerificationException $e) {
    // Invalid signature
    http_response_code(400);
    exit();
}

// Handle ang event
if ($event->type == 'checkout.session.completed') {
    $session = $event->data->object;

    // Kunin ang booking_id na sinet natin sa 'metadata'
    $booking_id = $session->metadata->booking_id;
    $stripe_checkout_id = $session->id; // Ang session ID galing sa Stripe

    try {
        // I-update ang database
        $stmt = $pdo->prepare(
            "UPDATE bookings 
             SET payment_status = 'paid', payment_checkout_id = ? 
             WHERE booking_id = ? AND payment_status = 'pending'"
        );
        $stmt->execute([$stripe_checkout_id, $booking_id]);
        
        // (Optional: DITO KA MAG-SEND NG CONFIRMATION EMAIL)
        
        file_put_contents('webhook_log.txt', "SUCCESS: Updated booking ID: $booking_id\n", FILE_APPEND);

    } catch (\PDOException $e) {
        file_put_contents('webhook_log.txt', "ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

http_response_code(200);
echo json_encode(['status' => 'success']);
?>