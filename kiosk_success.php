<?php
/**
 * kiosk_success.php
 * Self-contained success handler for kiosk card payments.
 * Does NOT use success.php — handles everything internally:
 * 1. Verifies N-Genius payment status
 * 2. Marks booking as paid
 * 3. Sends confirmation email
 * 4. Redirects to kiosk_book.php?success=1&booking_id=XXX
 */
session_start();
include_once 'db_connect.php';
include_once 'email_helper.php';

date_default_timezone_set('Asia/Dubai');

$_SESSION['kiosk_mode'] = true;

//define('NGENIUS_API_KEY', 'MDY4NDM2YWQtMjJhYi00ZTA3LTg2ODktODdmZTVlOTY0YjhkOjE4OTE0MjBiLThkNDYtNGIwYy04ZTJiLTE3Y2EzYTI5YTZhZQ=='); 
//define('NGENIUS_OUTLET_REF', '0d3b4577-1b43-4cb7-89e6-4dfceafa678e'); 

//define('NGENIUS_AUTH_URL', 'https://api-gateway.sandbox.ngenius-payments.com/identity/auth/access-token');
//define('NGENIUS_ORDER_URL', 'https://api-gateway.sandbox.ngenius-payments.com/transactions/outlets/' . NGENIUS_OUTLET_REF . '/orders');
// ============================================================================================================================================================
// 🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴ORIGINAL LIVE KEY🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴
// ============================================================================================================================================================

define('NGENIUS_API_KEY', 'NTc0MjQ4MGUtMTQ4OC00NzYzLTgxODktODgzYjQ3ZGZkZjQ2OjllMDVlMjczLWUxYjQtNDliZS04MDg1LWUyYmYyNmE5YTg3MQ=='); 
define('NGENIUS_OUTLET_REF', '4a6f1b5e-610f-4440-8e3c-4fb6ddff7d1a'); 

define('NGENIUS_AUTH_URL', 'https://api-gateway.ngenius-payments.com/identity/auth/access-token');
define('NGENIUS_ORDER_URL', 'https://api-gateway.ngenius-payments.com/transactions/outlets/' . NGENIUS_OUTLET_REF . '/orders');

// ============================================================================================================================================================
// 🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴🔴
// ============================================================================================================================================================



// ============================================================
// 1. GET BOOKING ID
// ============================================================
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;

if (!$booking_id) {
    header('Location: kiosk_book.php');
    exit;
}

// Fetch booking
$stmtBook = $pdo->prepare("SELECT * FROM bookings WHERE booking_id = ? LIMIT 1");
$stmtBook->execute([$booking_id]);
$booking_details = $stmtBook->fetch(PDO::FETCH_ASSOC);

if (!$booking_details) {
    header('Location: kiosk_book.php?error=booking_not_found');
    exit;
}

// ============================================================
// 2. VERIFY N-GENIUS PAYMENT
// ============================================================
$payment_method = strtolower($booking_details['payment_method'] ?? 'card');

// If payment is via QR points or cash, skip N-Genius verification
$bypass_ngenius = ['cash', 'qr_points', 'tabby'];

if (!in_array($payment_method, $bypass_ngenius)) {
    if (!isset($_GET['ref'])) {
        header('Location: kiosk_book.php?error=no_payment_ref');
        exit;
    }

    $order_ref = $_GET['ref'];

    // Get N-Genius access token
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, NGENIUS_AUTH_URL);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "accept: application/vnd.ni-identity.v1+json",
        "authorization: Basic " . NGENIUS_API_KEY,
        "content-type: application/vnd.ni-identity.v1+json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $token_response = curl_exec($ch);
    curl_close($ch);

    $token_data = json_decode($token_response, true);
    $access_token = $token_data['access_token'] ?? null;

    if (!$access_token) {
        header('Location: kiosk_book.php?error=gateway_auth_failed');
        exit;
    }

    // Check order status
    $check_url = NGENIUS_ORDER_URL . '/' . $order_ref;
    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, $check_url);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $access_token,
        "Accept: application/vnd.ni-payment.v2+json"
    ]);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, false);
    $order_info_json = curl_exec($ch2);
    curl_close($ch2);

    $order_info = json_decode($order_info_json, true);
    $order_state = $order_info['_embedded']['payment'][0]['state'] ?? 'UNKNOWN';

    // Only CAPTURED, AUTHORISED, PURCHASED are valid
    if (!in_array($order_state, ['CAPTURED', 'AUTHORISED', 'PURCHASED'])) {
        header('Location: kiosk_book.php?error=payment_failed&state=' . $order_state);
        exit;
    }
}

// ============================================================
// 3. MARK BOOKING AS PAID
// ============================================================
if (($booking_details['payment_status'] ?? '') === 'pending' && !in_array($payment_method, ['cash'])) {
    $stmt = $pdo->prepare("UPDATE bookings SET payment_status = 'paid' WHERE booking_id = ?");
    $stmt->execute([$booking_id]);
    $booking_details['payment_status'] = 'paid';
}

// ============================================================
// 4. SEND CONFIRMATION EMAIL
// ============================================================
try {
    if (!empty($booking_details['customer_email'])) {
        // Fetch booking items
        $stmt_items = $pdo->prepare("SELECT product_id, quantity, price_per_item as price FROM booking_items WHERE booking_id = ?");
        $stmt_items->execute([$booking_id]);
        $rawItems = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rawItems as $row) {
            $name = "Unknown Item";
            $pid = $row['product_id'];

            if (strpos($pid, 'type_') === 0) {
                $typeId = str_replace('type_', '', $pid);
                $stmtT = $pdo->prepare("SELECT tt.category, tt.sub_label, p.name as package_name FROM ticket_types tt LEFT JOIN products p ON tt.product_id = p.product_id WHERE tt.type_id = ?");
                $stmtT->execute([$typeId]);
                $t = $stmtT->fetch();
                if ($t) {
                    $pkg = !empty($t['package_name']) ? $t['package_name'] . ' - ' : '';
                    $sub = !empty($t['sub_label']) ? ' (' . $t['sub_label'] . ')' : '';
                    $name = $pkg . $t['category'] . $sub;
                }
            } else {
                $stmtP = $pdo->prepare("SELECT name FROM products WHERE product_id = ?");
                $stmtP->execute([$pid]);
                $p = $stmtP->fetch();
                if ($p) $name = $p['name'];
            }

            $items[] = [
                'product_id' => $pid,
                'name' => $name,
                'quantity' => $row['quantity'],
                'price' => $row['price']
            ];
        }

        // Include bundle add-ons from addon_redemptions (e.g. Meal Vouchers)
        $existingPids = array_column($items, 'product_id');
        $stmt_bundle = $pdo->prepare("
            SELECT ar.product_id, ar.quantity_total AS quantity, p.name, p.price 
            FROM addon_redemptions ar 
            LEFT JOIN products p ON ar.product_id = p.product_id 
            WHERE ar.booking_id = ?
        ");
        $stmt_bundle->execute([$booking_id]);
        $bundleAddons = $stmt_bundle->fetchAll(PDO::FETCH_ASSOC);

        foreach ($bundleAddons as $arow) {
            if (in_array($arow['product_id'], $existingPids)) continue;
            $items[] = [
                'product_id' => $arow['product_id'],
                'name' => $arow['name'] ?? $arow['product_id'],
                'quantity' => (int)$arow['quantity'],
                'price' => 0
            ];
        }

        // Send email
        if (function_exists('sendEntryReceipt')) {
            sendEntryReceipt(
                $booking_details['customer_email'],
                $booking_details['customer_name'],
                $booking_id,
                $booking_details,
                $items,
                "Self-Service Kiosk"
            );
        }
    }
} catch (Throwable $e) {
    // Email failure is non-blocking - log and continue
    error_log("kiosk_success email error: " . $e->getMessage());
}

// ============================================================
// 5. CLEAR SESSIONS
// ============================================================
unset($_SESSION['cart']);
unset($_SESSION['total_price']);
unset($_SESSION['booking_date']);
unset($_SESSION['discount_applied']);
unset($_SESSION['guest']);
unset($_SESSION['guest_details']);

// ============================================================
// 6. REDIRECT TO KIOSK_BOOK.PHP WITH SUCCESS POPUP
// ============================================================
header("Location: kiosk_book.php?success=1&booking_id=" . $booking_id . "&method=" . $payment_method);
exit;
