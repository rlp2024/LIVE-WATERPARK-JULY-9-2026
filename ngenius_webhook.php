<?php
// ngenius_webhook.php - WITH OPERATIONAL ALIVE MONITOR
include_once 'db_connect.php';

// 🟢 BROWSER MONITOR: If a human opens this link in Chrome/Safari
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "<div style='font-family:sans-serif; text-align:center; margin-top:50px; color:#334155;'>";
    echo "<h2 style='color:#16a34a;'>🟢 N-Genius Webhook is ALIVE & WAITING!</h2>";
    echo "<p>This URL is working perfectly. It is currently listening for secure background signals from the bank network.</p>";
    echo "<p style='font-size:12px; color:#64748b;'>Current Server Time: " . date('Y-m-d H:i:s') . "</p>";
    echo "</div>";
    exit;
}

// 🌐 ACTUAL BACKGROUND FLOW: Executed only when N-Genius sends a secure POST request
$raw_input = file_get_contents('php://input');
$event = json_decode($raw_input, true);

// Create a small text log file to trace if N-Genius actually sent data
file_put_contents('webhook_log.txt', date('Y-m-d H:i:s') . " - Data: " . $raw_input . PHP_EOL, FILE_APPEND);

if (isset($event['order']['reference'])) {
    $order_ref = $event['order']['reference'];
    $payment_state = $event['order']['_embedded']['payment'][0]['state'] ?? '';

    if (in_array($payment_state, ['CAPTURED', 'AUTHORISED', 'PURCHASED'])) {
        try {
            $stmtCheck = $pdo->prepare("SELECT booking_id FROM bookings WHERE transaction_id = ? LIMIT 1");
            $stmtCheck->execute([$order_ref]);
            $booking = $stmtCheck->fetch();

            if ($booking) {
                $booking_id = $booking['booking_id'];

                // Automatically update the database to PAID in the background!
                $stmtUpdate = $pdo->prepare("UPDATE bookings SET payment_status = 'paid' WHERE booking_id = ?");
                $stmtUpdate->execute([$booking_id]);
                
                http_response_code(200);
                echo "Success: Booking cleared.";
                exit;
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo "Database Error";
            exit;
        }
    }
}

// Always tell N-Genius we received the signal
http_response_code(200);
echo "Signal received.";
?>