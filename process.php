<?php
// process.php - UNIFIED BOOKING & KIOSK PROCESSOR (WITH N-GENIUS)
session_start();
function getGuestPayloadForBooking(): array {
    // Prefer guest_details if present
    if (!empty($_SESSION['guest_details']) && is_array($_SESSION['guest_details'])) {
        return [
            'customer_name'  => $_SESSION['guest_details']['customer_name']  ?? null,
            'customer_email' => $_SESSION['guest_details']['customer_email'] ?? null,
            'customer_phone' => $_SESSION['guest_details']['customer_phone'] ?? null,
            'phone_code'     => $_SESSION['guest_details']['phone_code']     ?? null,
            'company_name'   => $_SESSION['guest_details']['company_name']   ?? null,
            'nationality'    => $_SESSION['guest_details']['nationality']    ?? null,
            'customer_type'  => $_SESSION['guest_details']['customer_type']  ?? null,
        ];
    }

    // Fallback to $_SESSION['guest'] (ito ang gamit mo sa UI)
    $g = $_SESSION['guest'] ?? [];
    $first = trim($g['first_name'] ?? '');
    $last  = trim($g['last_name'] ?? '');

    return [
        'customer_name'  => trim(($first . ' ' . $last)) ?: null,
        'customer_email' => !empty($g['email']) ? trim($g['email']) : null,
        'customer_phone' => !empty($g['phone']) ? trim((string)$g['phone']) : null,
        'phone_code'     => !empty($g['phone_code']) ? trim($g['phone_code']) : null,
        'company_name'   => (isset($g['company_name']) && trim($g['company_name']) !== '') ? trim($g['company_name']) : null,
        'nationality'    => (isset($g['country']) && trim($g['country']) !== '') ? trim($g['country']) : null,
        'customer_type'  => (isset($g['are_you']) && in_array($g['are_you'], ['visitor','residence'], true)) ? $g['are_you'] : null,
    ];
}

include_once 'db_connect.php';
include_once 'email_helper.php';

date_default_timezone_set('Asia/Dubai');

// ==============================================================================
// [ADDED] CSRF + KIOSK FLAG (from checkout.php hidden fields)
// ==============================================================================
$isKioskCheckout = false;
if ((isset($_POST['is_kiosk']) && $_POST['is_kiosk'] == '1') || (isset($_SESSION['kiosk_mode']) && $_SESSION['kiosk_mode'] === true)) {
    $isKioskCheckout = true;
    $_SESSION['kiosk_mode'] = true;
}

// CSRF validation only if token is present (won't break other legacy forms)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && !empty($_SESSION['csrf_token'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid session token. Please refresh and try again.");
    }
}

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


//define('NGENIUS_API_KEY', 'MDY4NDM2YWQtMjJhYi00ZTA3LTg2ODktODdmZTVlOTY0YjhkOjE4OTE0MjBiLThkNDYtNGIwYy04ZTJiLTE3Y2EzYTI5YTZhZQ=='); 
//define('NGENIUS_OUTLET_REF', '0d3b4577-1b43-4cb7-89e6-4dfceafa678e'); 

//define('NGENIUS_AUTH_URL', 'https://api-gateway.sandbox.ngenius-payments.com/identity/auth/access-token');
//define('NGENIUS_ORDER_URL', 'https://api-gateway.sandbox.ngenius-payments.com/transactions/outlets/' . NGENIUS_OUTLET_REF . '/orders');
// LIVE URLs (Comment out sa taas, Uncomment ito pag live na):

$YOUR_DOMAIN = 'https://ajmanwaterpark.com'; // Siguraduhing tama ito

// ==============================================================================
// ðŸŸ¢ HELPER: GET N-GENIUS ACCESS TOKEN
// ==============================================================================
function getNGeniusAccessToken() {
    $ch = curl_init();
    $headers = [
        "accept: application/vnd.ni-identity.v1+json",
        "authorization: Basic " . NGENIUS_API_KEY,
        "content-type: application/vnd.ni-identity.v1+json"
    ];
    curl_setopt($ch, CURLOPT_URL, NGENIUS_AUTH_URL);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Uncomment if having SSL issues locally
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    if(curl_errno($ch)) die('N-Genius Auth Error: ' . curl_error($ch));
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

// ==============================================================================
// ðŸŸ¢ HELPER: CREATE N-GENIUS ORDER
// ==============================================================================
function createNGeniusOrder($token, $amount, $currency, $email, $redirectUrl, $cancelUrl) {
    $minorAmount = (int)($amount * 100);

    $postData = [
        "action" => "SALE",
        "amount" => [
            "currencyCode" => $currency,
            "value" => $minorAmount
        ],
        "emailAddress" => $email,
        "merchantAttributes" => [
            "redirectUrl" => $redirectUrl,
            "cancelUrl" => $cancelUrl,
            "skipConfirmationPage" => true
        ]
    ];

    $ch = curl_init();
    $headers = [
        "Authorization: Bearer " . $token,
        "Content-Type: application/vnd.ni-payment.v2+json",
        "Accept: application/vnd.ni-payment.v2+json"
    ];

    curl_setopt($ch, CURLOPT_URL, NGENIUS_ORDER_URL);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    if(curl_errno($ch)) die('N-Genius Order Error: ' . curl_error($ch));
    curl_close($ch);

    return json_decode($response, true);
}

// ==============================================================================
// ðŸ”´ KIOSK INTERCEPTOR: HANDLES ADD-ON TOP-UPS FROM SCANNER
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kiosk_mode']) && $_POST['kiosk_mode'] == '1') {

    if (!isset($_SESSION['admin_logged_in'])) {
        // header("Location: admin_login.php"); exit; 
    }

    $bookingId = (int)$_POST['booking_id'];
    $quantities = $_POST['qty'] ?? [];
    $paymentMethod = $_POST['payment_method'] ?? 'cash';
    $adminName = $_SESSION['admin_fullname'] ?? 'Kiosk User';

    $isSelfService = isset($_SESSION['kiosk_mode']) && $_SESSION['kiosk_mode'] === true;

    if ($isSelfService) {
        $successRedirect = $YOUR_DOMAIN . '/success.php?booking_id=' . $bookingId . '&topup=1';
        $cancelRedirect  = $YOUR_DOMAIN . '/kiosk_home.php';
    } else {
        $returnUrlFile = isset($_POST['return_url']) && !empty($_POST['return_url']) ? $_POST['return_url'] : 'admin_addon_scanner_base.php';
        $successRedirect = $YOUR_DOMAIN . '/' . $returnUrlFile . '?booking_id=' . $bookingId . '&msg=topup_success';
        $cancelRedirect  = $YOUR_DOMAIN . '/' . $returnUrlFile . '?booking_id=' . $bookingId;
    }

    if ($paymentMethod === 'card') {
        try {
            $totalAmount = 0;
            $stmtPrice = $pdo->prepare("SELECT price FROM products WHERE product_id = ?");
            foreach ($quantities as $pid => $qty) {
                if ((int)$qty > 0) {
                    $stmtPrice->execute([$pid]);
                    $prod = $stmtPrice->fetch(PDO::FETCH_ASSOC);
                    if ($prod) $totalAmount += ($prod['price'] * $qty);
                }
            }

       if ($totalAmount <= 0) die("Error: No items selected or total is 0.");
            
            // Add 5% VAT for Kiosk Card Top-ups
            $vatAmount = $totalAmount * 0.05;
            $grandTotalTopup = $totalAmount + $vatAmount;

            saveKioskTransaction($pdo, $bookingId, $quantities, 'pending_card', $adminName);

            $token = getNGeniusAccessToken();
            if (!$token) die("Payment Gateway Error: Unable to authenticate.");

            // Charge the Grand Total (with VAT)
            $orderResponse = createNGeniusOrder($token, $grandTotalTopup, 'AED', 'kiosk@ajmanwaterpark.com', $successRedirect, $cancelRedirect);


            if (isset($orderResponse['_links']['payment']['href'])) {
                header("Location: " . $orderResponse['_links']['payment']['href']);
                exit;
            } else {
                echo "Payment Creation Failed: <br>";
                print_r($orderResponse);
                exit;
            }

        } catch (Exception $e) {
            die("Error: " . $e->getMessage());
        }
    } else {
        try {
            saveKioskTransaction($pdo, $bookingId, $quantities, $paymentMethod, $adminName);
            header("Location: " . $successRedirect);
            exit;
        } catch (Exception $e) {
            die("Transaction Error: " . $e->getMessage());
        }
    }
}

// --- HELPER FUNCTION: SAVE TO DB AND SEND EMAIL ---
function saveKioskTransaction($pdo, $bookingId, $quantities, $paymentMethod, $adminName) {

    if ($pdo->inTransaction()) { } else { $pdo->beginTransaction(); }

    try {
        $totalAmount = 0;
        $itemsSummary = [];
        $purchasedItems = [];

        $stmtPrice  = $pdo->prepare("SELECT name, price FROM products WHERE product_id = ?");
        $stmtItem   = $pdo->prepare("INSERT INTO booking_items (booking_id, product_id, quantity, price_per_item) VALUES (?, ?, ?, ?)");

        $stmtRedeem = $pdo->prepare("
            INSERT INTO addon_redemptions (booking_id, product_id, unique_code, quantity_total, quantity_used, status) 
            VALUES (?, ?, ?, ?, 0, 'unused') 
            ON DUPLICATE KEY UPDATE quantity_total = quantity_total + VALUES(quantity_total), status = IF(quantity_total > quantity_used, 'unused', status)
        ");

        foreach ($quantities as $pid => $qty) {
            $qty = (int)$qty;
            if ($qty > 0) {
                $stmtPrice->execute([$pid]);
                $prod = $stmtPrice->fetch(PDO::FETCH_ASSOC);

                if ($prod) {
                    $price = $prod['price'];
                    $name = $prod['name'];
                    $subtotal = $price * $qty;
                    $totalAmount += $subtotal;
                    $itemsSummary[] = "$pid (x$qty)";

                    $stmtItem->execute([$bookingId, $pid, $qty, $price]);
                    $uniqueCode = $bookingId . '-' . $pid;
                    $stmtRedeem->execute([$bookingId, $pid, $uniqueCode, $qty]);

                    $purchasedItems[] = [
                        'name' => $name, 'product_id' => $pid, 'quantity' => $qty, 'price' => $price, 'new_total' => null
                    ];
                }
            }
        }

        if ($totalAmount > 0) {
            $summaryStr = implode(", ", $itemsSummary);
            $status = ($paymentMethod == 'pending_card') ? 'pending' : 'paid';

          $vatAmount = $totalAmount * 0.05;
            $grandTotalTopup = $totalAmount + $vatAmount;

            $stmtLog = $pdo->prepare("INSERT INTO addon_purchase_logs (booking_id, action, payment_status, payment_method, total_amount, vat_amount, items_summary, created_by) VALUES (?, 'TOPUP', ?, ?, ?, ?, ?, ?)");
            $stmtLog->execute([$bookingId, $status, $paymentMethod, $grandTotalTopup, $vatAmount, $summaryStr, $adminName]);

            if ($status == 'paid') {
                $pdo->prepare("UPDATE bookings SET total_amount = total_amount + ?, vat_amount = vat_amount + ? WHERE booking_id = ?")->execute([$grandTotalTopup, $vatAmount, $bookingId]);
            }
            
            
            
        }

        $pdo->commit();

        if ($paymentMethod !== 'pending_card') {
            $stmtCust = $pdo->prepare("SELECT customer_email, customer_name FROM bookings WHERE booking_id = ?");
            $stmtCust->execute([$bookingId]);
            $cust = $stmtCust->fetch(PDO::FETCH_ASSOC);
            if ($cust && !empty($cust['customer_email']) && function_exists('sendAddonPurchaseReceipt')) {
                sendAddonPurchaseReceipt($cust['customer_email'], $cust['customer_name'], $bookingId, $purchasedItems, $paymentMethod, $adminName);
            }
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
// ==============================================================================
// ðŸ”´ END KIOSK INTERCEPTOR
// ==============================================================================


// ------------------------------------------------------------------------------
// ORIGINAL LOGIC STARTS HERE (For Website Bookings)
// ------------------------------------------------------------------------------

// ==============================================================================
// [ADDED] Smart redirect for kiosk checkout if guest/cart missing
// ==============================================================================
if ((empty($_SESSION['cart']) || empty($_SESSION['guest'])) && $isKioskCheckout) {
    header('Location: checkout.php?mode=kiosk');
    exit;
}

if (empty($_SESSION['cart']) || empty($_SESSION['guest'])) {
    header('Location: book.php');
    exit;
}

$topupForBookingId = isset($_SESSION['topup_for_booking_id']) ? (int)$_SESSION['topup_for_booking_id'] : 0;
$isTopup = ($topupForBookingId > 0);

$payment_method = $_POST['payment_method'] ?? 'card';
$cart = $_SESSION['cart'];
$user = $_SESSION['guest'];
$total = $_SESSION['total_price'] ?? 0;
$date = $_SESSION['booking_date'] ?? date('Y-m-d');
$full_name = ($user['first_name'] ?? 'Guest') . ' ' . ($user['last_name'] ?? '');
$discount_code = $_SESSION['discount_applied']['code'] ?? null;
$discount_amount = $_SESSION['discount_applied']['amount'] ?? 0.00;


// ==============================================================================
// âœ… UPDATE: TOP-UP RULES
// - Tabby: blocked always for topup
// - Cash: allowed ONLY if kiosk checkout (kiosk buy add ons only.php -> checkout.php -> process.php)
// ==============================================================================
if ($isTopup && $payment_method === 'tabby') {
    echo "Top-up purchases are available via Card only.";
    exit;
}

if ($isTopup && $payment_method === 'cash') {

    if (!$isKioskCheckout) {
        echo "Cash top-up is available via Kiosk only.";
        exit;
    }

    // Convert SESSION cart -> quantities[product_id] = qty
    $quantities = [];
    foreach ($cart as $item) {
        $rawId = $item['id'] ?? '';
        $pid = str_replace('prod_', '', $rawId);
        if ($pid !== '') {
            $qty = (int)($item['quantity'] ?? 0);
            if ($qty > 0) $quantities[$pid] = $qty;
        }
    }

    if (empty($quantities)) {
        echo "Error: No add-ons selected.";
        exit;
    }

    // Save topup directly to ORIGINAL booking (parent booking id)
    $adminName = $_SESSION['admin_fullname'] ?? 'Kiosk Cashier';
    saveKioskTransaction($pdo, $topupForBookingId, $quantities, 'cash', $adminName);

    // Optional cleanup
    unset($_SESSION['cart'], $_SESSION['discount_applied'], $_SESSION['total_price']);

    header("Location: " . $YOUR_DOMAIN . "/success.php?topup=1&parent_booking_id=" . $topupForBookingId . "&cash=1");
    exit;
}
      
// ==============================================================================
// âœ… NEW: TOP-UP GAMIT ANG QR POINTS (BUMIBILI NG ADD-ONS SA KIOSK)
// ==============================================================================
if ($isTopup && $payment_method === 'qr_points') {
    $qr_code = $_POST['scanned_qr_code'] ?? '';
    
    if (empty($qr_code)) {
        die("Error: Please scan your QR Card before paying.");
    }

    // 1. Hanapin ang Wallet at tingnan kung sapat ang points
    $input_id = trim($_POST['scanned_qr_code']); // O kung ano man ang variable na ginamit mo
    $scanned_qr = trim($_POST['scanned_qr_code'] ?? '');
    $stmtWallet = $pdo->prepare("SELECT * FROM qr_wallets WHERE (qr_code = ? OR wallet_id = ?) AND status = 'active'");
    $stmtWallet->execute([$input_id, $input_id]);

    $wallet = $stmtWallet->fetch();

    if ($wallet && $wallet['balance'] >= $total) {
        $pdo->beginTransaction();
        
        // 2. Ibawas ang points agad-agad sa database para safe sa double-click
        $updateStmt = $pdo->prepare("UPDATE qr_wallets SET balance = balance - ? WHERE wallet_id = ? AND balance >= ?");
        $updateStmt->execute([$total, $wallet['wallet_id'], $total]);

        if ($updateStmt->rowCount() === 0) {
            $pdo->rollBack();
            die("Error: Transaction conflict or insufficient balance.");
        }

        // 3. I-log ang transaction
        $logStmt = $pdo->prepare("INSERT INTO qr_transactions (wallet_id, transaction_type, points, description, reference_id) VALUES (?, 'purchase', ?, 'Paid for Top-up Add-ons via Kiosk', ?)");
        $logStmt->execute([$wallet['wallet_id'], $total, $topupForBookingId]);

        // Convert SESSION cart -> quantities[product_id] = qty
    $quantities = [];
    foreach ($cart as $item) {
        $rawId = $item['id'] ?? '';
        $pid = str_replace('prod_', '', $rawId);
        if ($pid !== '') {
            $qty = (int)($item['quantity'] ?? 0);
            if ($qty > 0) $quantities[$pid] = $qty;
        }
    }

        // 5. I-save sa booking items at i-send ang resibo
        // PANSININ: Ang saveKioskTransaction() ang mismong mag-cocommit ng transaction.
        $adminName = $_SESSION['admin_fullname'] ?? 'Kiosk Self-Service';
        saveKioskTransaction($pdo, $topupForBookingId, $quantities, 'qr_points', $adminName);

        // 6. Linisin ang cart at i-redirect sa success page
        unset($_SESSION['cart'], $_SESSION['discount_applied'], $_SESSION['total_price']);
        header("Location: " . $YOUR_DOMAIN . "/success.php?topup=1&parent_booking_id=" . $topupForBookingId);
        exit;

    } else {
        die("Error: Invalid QR Card or Insufficient Points.");
    }
}
    
try {

    // ===============================================
    // A. ONLINE TOP-UP FLOW (N-GENIUS)
    // ===============================================
    if ($isTopup) {
        $calcTotal = 0;
        foreach($cart as $item) {
            $calcTotal += ($item['price'] * $item['quantity']);
        }
        $finalAmount = $calcTotal - $discount_amount;
        if ($finalAmount < 0) $finalAmount = 0;

        $token = getNGeniusAccessToken();
        if (!$token) die("Payment Gateway Error.");

        $successUrl = $YOUR_DOMAIN . '/success.php?topup=1&parent_booking_id=' . $topupForBookingId;
        $cancelUrl  = $YOUR_DOMAIN . '/admin_verify_addons.php?booking_id=' . $topupForBookingId;

        $orderResponse = createNGeniusOrder($token, $finalAmount, 'AED', $user['email'], $successUrl, $cancelUrl);

        if (isset($orderResponse['_links']['payment']['href'])) {
            header("Location: " . $orderResponse['_links']['payment']['href']);
            exit;
        } else {
            die("Error creating payment order.");
        }
    }

    // ===============================================
    // B. MAIN BOOKING FLOW (Ticket Generation)
    // ===============================================
    $pdo->beginTransaction();

    // 1. SAVE FACE IMAGE
    $face_db_path = null;
    if (isset($_SESSION['guest']['face_image']) && !empty($_SESSION['guest']['face_image'])) {
        $img = $_SESSION['guest']['face_image'];
        $img = str_replace('data:image/jpeg;base64,', '', $img);
        $img = str_replace(' ', '+', $img);
        $data = base64_decode($img);
        if (!file_exists('uploads/faces/')) mkdir('uploads/faces/', 0777, true);
        $fileName = 'face_' . time() . '_' . rand(1000,9999) . '.jpg';
        if (file_put_contents('uploads/faces/' . $fileName, $data)) {
            $face_db_path = $fileName;
        }
    }

    $expiry_date = $_SESSION['expiry_date'] ?? null;

    // âœ… SAFELY GET EMAIL + PHONE
    $email = $_POST['email'] ?? ($_SESSION['user']['email'] ?? ($user['email'] ?? null));
    $phone = $_POST['phone'] ?? ($_SESSION['user']['phone'] ?? ($user['phone'] ?? null));

    $email = $email ? trim($email) : null;
    $phone = $phone ? trim($phone) : null;

    if (empty($email)) {
        $email = 'kiosk_' . time() . '@ajmanwaterpark.local';
    }

    // Ensure session guest email is always filled
    if (!isset($_SESSION['guest']['email']) || empty($_SESSION['guest']['email'])) {
        $_SESSION['guest']['email'] = $email;
        $user['email'] = $email;
    }

    $first_name   = trim($_POST['first_name'] ?? '');
    $last_name    = trim($_POST['last_name'] ?? '');
    $customer_name = trim($first_name . ' ' . $last_name);

    $customer_email = trim($_POST['email'] ?? '');
    $company_name   = trim($_POST['company_name'] ?? '');

    $phone_code = trim($_POST['phone_code'] ?? '');
    $allowed_codes = ['+971', '+63', '+1'];
    if (!in_array($phone_code, $allowed_codes, true)) {
        $phone_code = null; // or default '+971'
    }

    $customer_phone = preg_replace('/\D+/', '', $_POST['phone'] ?? ''); // digits only

    $nationality = trim($_POST['country'] ?? '');

    $customer_type = $_POST['are_you'] ?? null;
    if (!in_array($customer_type, ['visitor', 'residence'], true)) {
        $customer_type = null;
    }

    
$vat_amount = $_SESSION['vat_amount'] ?? 0.00;

// Kunin ang boolean values mula sa session (0 ang default kung wala)
    $agreed_terms = $_SESSION['guest']['agreed_terms'] ?? ($_SESSION['guest_details']['agreed_terms'] ?? 0);
    $agreed_refund = $_SESSION['guest']['agreed_refund_policy'] ?? ($_SESSION['guest_details']['agreed_refund_policy'] ?? 0);

    $stmt = $pdo->prepare("INSERT INTO bookings
        (customer_name, customer_email, customer_phone, total_amount, discount_code, discount_amount, vat_amount, visit_date, expiry_date, payment_status, payment_method, face_image_path, agreed_terms, agreed_refund_policy, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, NOW())");

    $stmt->execute([
        trim($full_name),
        $email,
        $phone,
        $total, // This is already the Grand Total from checkout
        $discount_code,
        $discount_amount,
        $vat_amount,
        $date,
        $expiry_date,
        $payment_method,
        $face_db_path,
        $agreed_terms,
        $agreed_refund
    ]);
    $booking_id = $pdo->lastInsertId();

    // =============================
    // SAVE GUEST DETAILS (detail.php)
    // =============================
    $first = trim($_POST['first_name'] ?? '');
    $last  = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? ($user['email'] ?? ''));
    $company = trim($_POST['company_name'] ?? '');
    $phone_code = trim($_POST['phone_code'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $are_you = trim($_POST['are_you'] ?? '');

    $full_phone = trim(($phone_code ? $phone_code . ' ' : '') . $phone);

    // Fallback to session guest values (important for kiosk checkout)
    if ($first === '')   $first   = trim($user['first_name'] ?? '');
    if ($last === '')    $last    = trim($user['last_name'] ?? '');
    if ($email === '')   $email   = trim($user['email'] ?? '');
    if ($phone === '')   $phone   = trim($user['phone'] ?? '');
    if ($country === '') $country = trim($user['country'] ?? '');

    if ($booking_id > 0 && $first !== '' && $last !== '' && $email !== '') {
        try {
            $stmtGD = $pdo->prepare("
                INSERT INTO guest_details
                    (booking_id, first_name, last_name, email, company_name, phone_code, phone, country, are_you, created_at, updated_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    first_name=VALUES(first_name),
                    last_name=VALUES(last_name),
                    email=VALUES(email),
                    company_name=VALUES(company_name),
                    phone_code=VALUES(phone_code),
                    phone=VALUES(phone),
                    country=VALUES(country),
                    are_you=VALUES(are_you),
                    updated_at=NOW()
            ");
            $stmtGD->execute([
                $booking_id,
                $first,
                $last,
                $email,
                $company,
                $phone_code,
                $full_phone,
                $country,
                $are_you
            ]);
        } catch (Exception $e) {
            // optional: error_log("Guest save error: " . $e->getMessage());
        }
    }
    // END SAVE GUEST DETAILS

    if ($discount_code) {
        $stmtUpdateDiscount = $pdo->prepare("UPDATE discount_codes SET usage_count = usage_count + 1 WHERE code = ?");
        $stmtUpdateDiscount->execute([$discount_code]);
    }

    // 3. INSERT ITEMS & GENERATE TICKETS
    $stmt_item = $pdo->prepare("INSERT INTO booking_items (booking_id, product_id, quantity, price_per_item) VALUES (?, ?, ?, ?)");
    $stmt_tkt  = $pdo->prepare("INSERT INTO ticket_instances (booking_id, ticket_code, ticket_type, status) VALUES (?, ?, ?, 'unused')");

    // Add-on redemption auto-create for main booking (category_id = 6)
    $stmtProdCat = $pdo->prepare("SELECT category_id FROM products WHERE product_id = ? LIMIT 1");
    $stmtRedeemMain = $pdo->prepare("
        INSERT INTO addon_redemptions (booking_id, product_id, unique_code, quantity_total, quantity_used, status) 
        VALUES (?, ?, ?, ?, 0, 'unused') 
        ON DUPLICATE KEY UPDATE 
            quantity_total = quantity_total + VALUES(quantity_total),
            status = IF(quantity_total > quantity_used, 'unused', status)
    ");

    $ticket_seq = 1;

    foreach($cart as $item) {
        $rawId = $item['id'];
        
        $db_save_id = str_replace('prod_', '', $rawId);
        
        // âœ¨ FIX 1406 ERROR: Paikliin natin ang ID para magkasya sa database
        $db_save_id = str_replace('qr_topup_new_', 'QRN_', $db_save_id);
        $db_save_id = str_replace('qr_topup_reload_', 'QRR_', $db_save_id);

        $stmt_item->execute([$booking_id, $db_save_id, $item['quantity'], $item['price']]);

        // If item is an ADD-ON (category_id=6), create/extend redemption record
        // FIX: Tinanggal ang ctype_digit gate para sumama din ang ADD-prefixed product IDs
        // (ADD1..ADD6 atbp). Ang ctype_digit ay TRUE lang para sa puro digits, kaya
        // ang lahat ng addon products na may letra ay hindi naka-record sa addon_redemptions.
        if ($db_save_id !== '' && strpos($db_save_id, 'QRN_') !== 0 && strpos($db_save_id, 'QRR_') !== 0) {
            $stmtProdCat->execute([$db_save_id]);
            $catRow = $stmtProdCat->fetch(PDO::FETCH_ASSOC);
            if ($catRow && (int)$catRow['category_id'] === 6) {
                $uniqueCode = $booking_id . '-' . $db_save_id;
                $stmtRedeemMain->execute([$booking_id, $db_save_id, $uniqueCode, (int)$item['quantity']]);
            }
        }

        // ===========================================
        // TICKET GENERATION LOGIC (FIXED for BUNDLES)
        // ===========================================
        $isTicket = false;
        $isBundle = false;
        $bundleConfigId = null;
        $autoMealAddon = false;  // FIX: tracker para sa "w/ Meal" tickets
        
        if (strpos($rawId, 'type_') === 0) { 
            $isTicket = true; 
            
            // --- FIX: Check kung "w/ Meal" ang ticket type â†’ auto-add ADD6 ---
            $typeIdNum = (int)str_replace('type_', '', $rawId);
            $stmtCheckMeal = $pdo->prepare("SELECT category, sub_label FROM ticket_types WHERE type_id = ? LIMIT 1");
            $stmtCheckMeal->execute([$typeIdNum]);
            $mealRow = $stmtCheckMeal->fetch(PDO::FETCH_ASSOC);
            if ($mealRow) {
                $combinedLabel = strtolower(($mealRow['category'] ?? '') . ' ' . ($mealRow['sub_label'] ?? ''));
                if (strpos($combinedLabel, 'meal') !== false) {
                    $autoMealAddon = true;
                }
                // FIX: Dapat 'meal' lang ang trigger. Ang lumang 'w/' check ay
                // tinanggal kasi tatamaan ang "Adult w/ Swim", "Adult w/o Swim"
                // atbp na hindi naman dapat may meal voucher.
            }
        }
        elseif (in_array($db_save_id, ['DP-RES', 'DP-NON', '3HRPSS', 'AP1', 'AP2', 'AP3', '1'])) {
            $isTicket = true;
        }
        // --- FIX: Check sa product_bundle_configs (works for FAM-PKG, etc.) ---
        else {
            $stmtBundleCfg = $pdo->prepare("SELECT config_id FROM product_bundle_configs WHERE main_product_id = ? AND is_active = 1 LIMIT 1");
            $stmtBundleCfg->execute([$db_save_id]);
            $bundleCfgRow = $stmtBundleCfg->fetch(PDO::FETCH_ASSOC);
            if ($bundleCfgRow) {
                $isTicket = true;
                $isBundle = true;
                $bundleConfigId = (int)$bundleCfgRow['config_id'];
            }
            // --- FALLBACK: Hardcoded FAM-PKG expansion kapag walang DB config ---
            // Para hindi mawala ang Family Package meal vouchers (4x ADD6) at tickets
            // (2 Adults + 2 Kids per bundle) kapag empty/inactive ang
            // product_bundle_configs / product_bundle_addons tables.
            elseif (strtoupper($db_save_id) === 'FAM-PKG') {
                $isTicket = true;
                $isBundle = true;
                $bundleConfigId = 0; // 0 = signal na gamitin ang hardcoded fallback
            }
        }

        if ($isTicket) {
            $qty = (int)$item['quantity'];
            
            if ($isBundle && ($bundleConfigId > 0 || $bundleConfigId === 0)) {
                // ====== BUNDLE: Generate tickets + addons ======

                // --- HARDCODED FAM-PKG fallback (kapag $bundleConfigId === 0) ---
                if ($bundleConfigId === 0) {
                    $variants = [
                        ['variant_name' => 'adult', 'quantity' => 2],
                        ['variant_name' => 'kids',  'quantity' => 2],
                    ];
                    $bundleAddons = [
                        ['addon_product_id' => 'ADD6'],
                        ['addon_product_id' => 'ADD6'],
                        ['addon_product_id' => 'ADD6'],
                        ['addon_product_id' => 'ADD6'],
                    ];
                } else {
                    // 1. Get bundle ticket variants (e.g. adult=2, kids=2)
                    $stmtVariants = $pdo->prepare("SELECT variant_name, quantity FROM product_bundle_ticket_qtys WHERE config_id = ?");
                    $stmtVariants->execute([$bundleConfigId]);
                    $variants = $stmtVariants->fetchAll(PDO::FETCH_ASSOC);

                    // 2. Get bundle add-ons (e.g. 4x ADD6 Meal Voucher)
                    $stmtBundleAddons = $pdo->prepare("SELECT addon_product_id FROM product_bundle_addons WHERE config_id = ?");
                    $stmtBundleAddons->execute([$bundleConfigId]);
                    $bundleAddons = $stmtBundleAddons->fetchAll(PDO::FETCH_ASSOC);

                    // FAM-PKG safety net: kung empty ang bundle DB tables, gamitin ang hardcoded
                    if (strtoupper($db_save_id) === 'FAM-PKG') {
                        if (empty($variants)) {
                            $variants = [
                                ['variant_name' => 'adult', 'quantity' => 2],
                                ['variant_name' => 'kids',  'quantity' => 2],
                            ];
                        }
                        if (empty($bundleAddons)) {
                            $bundleAddons = [
                                ['addon_product_id' => 'ADD6'],
                                ['addon_product_id' => 'ADD6'],
                                ['addon_product_id' => 'ADD6'],
                                ['addon_product_id' => 'ADD6'],
                            ];
                        }
                    }
                }

                $variantLabels = [
                    'adult'    => 'Adult',
                    'kids'     => 'Kids',
                    'children' => 'Children',
                    'infant'   => 'Infant'
                ];
                
                if (!empty($variants)) {
                    // Ginagawang malinaw ang label ng bundle tickets, hal.
                    // "Family Package - Adult" / "Family Package - Kids". Mahalaga
                    // ito para reliable ang pagkilala sa gate sync (hindi malito
                    // ang FAM-PKG sa ibang package kapag magkasabay sa booking).
                    $bundleName = trim((string)($item['name'] ?? 'Bundle'));
                    for ($b = 0; $b < $qty; $b++) {
                        foreach ($variants as $v) {
                            $vKey = strtolower($v['variant_name']);
                            $vLabel = $variantLabels[$vKey] ?? ucfirst($v['variant_name']);
                            $label = $bundleName !== '' ? ($bundleName . ' - ' . $vLabel) : $vLabel;
                            $variantQty = (int)$v['quantity'];
                            
                            for ($i = 0; $i < $variantQty; $i++) {
                                $unique_code = $booking_id . '-' . str_pad($ticket_seq, 2, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(md5(uniqid()), 0, 3));
                                $stmt_tkt->execute([$booking_id, $unique_code, $label]);
                                $ticket_seq++;
                            }
                        }
                    }
                } else {
                    // Fallback kung walang variants config
                    for ($i = 0; $i < $qty; $i++) {
                        $unique_code = $booking_id . '-' . str_pad($ticket_seq, 2, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(md5(uniqid()), 0, 3));
                        $stmt_tkt->execute([$booking_id, $unique_code, $item['name']]);
                        $ticket_seq++;
                    }
                }
                
                if (!empty($bundleAddons)) {
                    // Bilangin per addon_product_id (kung paulit-ulit sa table)
                    $addonCounts = [];
                    foreach ($bundleAddons as $ba) {
                        $apid = $ba['addon_product_id'];
                        if (!isset($addonCounts[$apid])) $addonCounts[$apid] = 0;
                        $addonCounts[$apid]++;
                    }
                    
                    foreach ($addonCounts as $apid => $perBundle) {
                        $totalQty = $perBundle * $qty;  // multiply by bundle qty
                        $uniqueCode = $booking_id . '-' . $apid;
                        $stmtRedeemMain->execute([$booking_id, $apid, $uniqueCode, $totalQty]);
                    }
                }
                
            } else {
                // ====== NORMAL TICKET LOGIC ======
                for ($i = 0; $i < $qty; $i++) {
                    $unique_code = $booking_id . '-' . str_pad($ticket_seq, 2, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(md5(uniqid()), 0, 3));
                    $stmt_tkt->execute([$booking_id, $unique_code, $item['name']]);
                    $ticket_seq++;
                }
                
                // --- FIX: Kung "w/ Meal" ticket, auto-create ADD6 (Meal Voucher) sa addon_redemptions ---
                if ($autoMealAddon) {
                    $mealCode = $booking_id . '-ADD6';
                    $stmtRedeemMain->execute([$booking_id, 'ADD6', $mealCode, $qty]);
                }
            }
        }
    }

    $guest = getGuestPayloadForBooking();

// If required guest fields missing, stop safely
if (empty($guest['customer_email']) || empty($guest['customer_name'])) {
    // go back safely (do not proceed to payment)
    header("Location: details.php");
    exit;
}

// SAFE UPDATE: only fills NULL/empty fields (won't overwrite existing non-empty data)
$upd = $pdo->prepare("
    UPDATE bookings SET
        customer_name  = CASE WHEN customer_name IS NULL OR customer_name = '' THEN :customer_name ELSE customer_name END,
        customer_email = CASE WHEN customer_email IS NULL OR customer_email = '' THEN :customer_email ELSE customer_email END,
        customer_phone = CASE WHEN customer_phone IS NULL OR customer_phone = '' THEN :customer_phone ELSE customer_phone END,
        phone_code     = CASE WHEN phone_code IS NULL OR phone_code = '' THEN :phone_code ELSE phone_code END,
        company_name   = CASE WHEN company_name IS NULL OR company_name = '' THEN :company_name ELSE company_name END,
        nationality    = CASE WHEN nationality IS NULL OR nationality = '' THEN :nationality ELSE nationality END,
        customer_type  = CASE WHEN customer_type IS NULL OR customer_type = '' THEN :customer_type ELSE customer_type END
    WHERE booking_id = :booking_id
");

$upd->execute([
    ':customer_name'  => $guest['customer_name'],
    ':customer_email' => $guest['customer_email'],
    ':customer_phone' => $guest['customer_phone'],
    ':phone_code'     => $guest['phone_code'],
    ':company_name'   => $guest['company_name'],
    ':nationality'    => $guest['nationality'],
    ':customer_type'  => $guest['customer_type'],
    ':booking_id'     => $booking_id,
]);


    // 4. PAYMENT ROUTING
    
    // NEW: QR WALLET POINTS FLOW
    if ($payment_method === 'qr_points') {
        $qr_code = $_POST['scanned_qr_code'] ?? '';
        
        if (empty($qr_code)) {
            $pdo->rollBack();
            die("Error: Please scan your QR Card before paying.");
        }

        // 1. Hanapin ang Wallet sa database
        $stmtWallet = $pdo->prepare("SELECT wallet_id, balance FROM qr_wallets WHERE (qr_code = ? OR wallet_id = ?) AND status = 'active'");
        $stmtWallet->execute([$qr_code, $qr_code]);

        $wallet = $stmtWallet->fetch();

        if ($wallet) {
            // 2. I-check kung sapat ang points (1 Point = 1 AED)
            if ($wallet['balance'] >= $total) {
                
                // 3. Bawasan ang balance directly sa SQL para safe sa double-clicks (Concurrency Fix)
                $updateStmt = $pdo->prepare("UPDATE qr_wallets SET balance = balance - ? WHERE wallet_id = ? AND balance >= ?");
                $updateStmt->execute([$total, $wallet['wallet_id'], $total]);

                if ($updateStmt->rowCount() === 0) {
                    $pdo->rollBack();
                    die("Error: Transaction conflict or insufficient balance during processing. Please try again.");
                }

                // 4. I-record ang transaction sa qr_transactions history
                $logStmt = $pdo->prepare("INSERT INTO qr_transactions (wallet_id, transaction_type, points, description, reference_id) VALUES (?, 'purchase', ?, 'Paid for booking/addons', ?)");
                $logStmt->execute([$wallet['wallet_id'], $total, $booking_id]);

                // 5. I-update ang Booking as 'paid' (TINAANGGAL ANG transaction_id PARA HINDI MAG-CRASH)
                $stmtUpdate = $pdo->prepare("UPDATE bookings SET payment_status = 'paid' WHERE booking_id = ?");
                $stmtUpdate->execute([$booking_id]);

                // 6. I-commit at idirekta sa success page
                $pdo->commit();

                // --- Sync sa turnstile gate (ligtas/inert hangga't walang GATE_AUTH_KEY) ---
                try { require_once __DIR__ . '/gate_sync.php'; gate_push_booking($pdo, (int)$booking_id); }
                catch (\Throwable $e) { @error_log('gate_push_booking failed: ' . $e->getMessage()); }

                $successPage = $isKioskCheckout ? 'kiosk_success.php' : 'success.php';
                header("Location: " . $YOUR_DOMAIN . "/" . $successPage . "?booking_id=" . $booking_id);
                exit;

            } else {
                $pdo->rollBack();
                die("Error: Insufficient QR Points. Your current balance is only " . number_format($wallet['balance'], 2) . " AED.");
            }
        } else {
            $pdo->rollBack();
            die("Error: Invalid QR Card or Card is not yet activated.");
        }
    }



    if ($payment_method === 'tabby') {
        $pdo->commit();
        header("Location: tabby_simulator.php?booking_id=" . $booking_id . "&amount=" . $total);
        exit;

    // ðŸ‘‡ðŸ‘‡ðŸ‘‡ NEW: CASH LOGIC (PENDING STATUS) ðŸ‘‡ðŸ‘‡ðŸ‘‡
    } elseif ($payment_method === 'cash') {
        // 1. Gawing 'pending' ang booking status at i-set sa 'cash'
        $stmtUpdate = $pdo->prepare("UPDATE bookings SET payment_status = 'pending', payment_method = 'cash' WHERE booking_id = ?");
        $stmtUpdate->execute([$booking_id]);

        // 2. I-record as 'pending' sa transaction history (hindi mag-rereflect as paid sales)
        $vat = $_SESSION['vat_amount'] ?? ($total * 0.05);
        $stmtLog = $pdo->prepare("INSERT INTO addon_purchase_logs (booking_id, action, payment_status, payment_method, total_amount, vat_amount) VALUES (?, 'BOOKING', 'pending', 'cash', ?, ?)");
        $stmtLog->execute([$booking_id, $total, $vat]);

        $pdo->commit();
        
        // 3. Direkta sa success page para sa instructions
        header("Location: success.php?booking_id=" . $booking_id);
        exit;
    // ðŸ‘†ðŸ‘†ðŸ‘† END OF CASH LOGIC ðŸ‘†ðŸ‘†ðŸ‘†

    } else {
        // --- N-GENIUS FLOW (MAIN BOOKING) ---
        $pdo->commit();

        $token = getNGeniusAccessToken();
        if (!$token) die("Payment Gateway Connection Error");

        if ($isKioskCheckout) {
            $successUrl = $YOUR_DOMAIN . '/kiosk_success.php?booking_id=' . $booking_id;
            $cancelUrl  = $YOUR_DOMAIN . '/kiosk_book.php?error=payment_cancelled';
        } else {
            $successUrl = $YOUR_DOMAIN . '/success.php?booking_id=' . $booking_id;
            $cancelUrl  = $YOUR_DOMAIN . '/book.php?error=payment_cancelled';
        }

        $orderResponse = createNGeniusOrder($token, $total, 'AED', $user['email'], $successUrl, $cancelUrl);

        if (isset($orderResponse['_links']['payment']['href'])) {

            if (isset($orderResponse['reference'])) {
                $ref = $orderResponse['reference'];
                // Update the booking with the N-Genius transaction reference
                $stmtRef = $pdo->prepare("UPDATE bookings SET transaction_id = ? WHERE booking_id = ?");
                $stmtRef->execute([$ref, $booking_id]);
            }

            header("Location: " . $orderResponse['_links']['payment']['href']);
            exit;
        } else {
            echo "Failed to initiate payment. <br>";
            print_r($orderResponse);
            exit;
        }
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo "System Error: " . $e->getMessage();
    exit;
}
?>