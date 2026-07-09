<?php
// success.php - FINAL FIXED VERSION (No Stripe Blockers)

// Debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include_once 'db_connect.php';
include_once 'email_helper.php';

// [MOVED] Ang QR Wallet Activator ay inilipat sa ibaba pagkatapos ng Payment Verification para secure!

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

// Detect Kiosk Mode
$is_kiosk = isset($_SESSION['kiosk_mode']) && $_SESSION['kiosk_mode'];

// =============================
// GET PARAMS
// =============================
$booking_id   = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;

// ✅ TOP-UP FLAGS (buy more add-ons for existing booking)
$isTopup = false;
$parent_booking_id = 0;

if (isset($_GET['topup']) && $_GET['topup'] == '1') {
    $isTopup = true;
}
if (isset($_GET['parent_booking_id'])) {
    $isTopup = true;
    $parent_booking_id = (int)$_GET['parent_booking_id'];
}

// If topup and booking_id not provided, use parent
if ($isTopup && $parent_booking_id > 0 && $booking_id <= 0) {
    $booking_id = $parent_booking_id;
}

// Normal flow requires booking_id
if (!$booking_id) {
    // Smart Redirect
    if ($is_kiosk) {
        header('Location: kiosk_home.php');
    } else {
        header('Location: index.php'); // <-- Babalik sa index.php kapag web user
    }
    exit;
}

$booking_details = null;
$email_error = "";

try {
    // 👇 IDAGDAG ITO PARA KUNIN ANG BOOKING SA DATABASE 👇
    if ($booking_id > 0) {
        $stmtBook = $pdo->prepare("SELECT * FROM bookings WHERE booking_id = ? LIMIT 1");
        $stmtBook->execute([$booking_id]);
        $booking_details = $stmtBook->fetch(PDO::FETCH_ASSOC);
    }

    if (!$booking_details) {
        die("System Error: Booking ID #$booking_id not found in the database.");
    }
    // 👆 HANGGANG DITO 👆    

    // ==============================================================================
    // 🔴 N-GENIUS PAYMENT VERIFICATION (Para hindi pumasok ang walang laman na card)
    // ==============================================================================
    // 1. Default: Kunin ang method mula sa database
    $payment_method = strtolower($booking_details['payment_method'] ?? 'card');

    // 2. Logic Override: Kung ito ay Top-up at may 'ref' sa URL, ibig sabihin CARD ang ginamit ngayon
    if (isset($_GET['ref'])) {
        $payment_method = 'card';
    } elseif ($isTopup && $is_kiosk && !isset($_GET['ref'])) {
        // Kung Top-up sa Kiosk at walang 'ref', malamang CASH ang pinili
        $payment_method = 'cash';
    }
    
    // Listahan ng mga payment methods na HINDI dadaan sa N-Genius API
    $bypass_ngenius = ['cash', 'qr_points', 'tabby'];
    
    // Kapag ang payment method ay wala sa bypass list (ibig sabihin Card/ApplePay), i-verify sa N-Genius
    if (!in_array($payment_method, $bypass_ngenius)) {
        if (!isset($_GET['ref'])) {
            die("Error: There is no payment reference from the payment gateway. Payment Method used: " . strtoupper($payment_method));
        }
        
        $order_ref = $_GET['ref'];
        
        // 1. Kumuha ng Access Token mula sa N-Genius
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, NGENIUS_AUTH_URL);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "accept: application/vnd.ni-identity.v1+json",
            "authorization: Basic " . NGENIUS_API_KEY,
            "content-type: application/vnd.ni-identity.v1+json"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        $token_response = curl_exec($ch);
        curl_close($ch);
        
        $token_data = json_decode($token_response, true);
        $access_token = $token_data['access_token'] ?? null;

        if (!$access_token) {
            die("System Error: Unable to connect to Payment Gateway to verify.");
        }

        // 2. I-check ang status ng Order gamit ang Access Token at Order Reference
        $check_url = NGENIUS_ORDER_URL . '/' . $order_ref;
        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, $check_url);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $access_token,
            "Accept: application/vnd.ni-payment.v2+json"
        ]);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        $order_info_json = curl_exec($ch2);
        curl_close($ch2);
        
        $order_info = json_decode($order_info_json, true);
        
        // Kunin ang state ng payment (CAPTURED, AUTHORISED, FAILED, DECLINED, etc.)
        $order_state = $order_info['_embedded']['payment'][0]['state'] ?? 'UNKNOWN';

        // Kung HINDI successful ang state, i-redirect pabalik sa error page
        if (!in_array($order_state, ['CAPTURED', 'AUTHORISED', 'PURCHASED'])) {
            echo "<h3>Payment Failed!</h3>";
            echo "<strong>Order State:</strong> " . $order_state . "<br><br>";
            echo "<strong>N-Genius Response:</strong><br>";
            echo "<pre>" . json_encode($order_info, JSON_PRETTY_PRINT) . "</pre>";
            exit;
        }
    }
    // ==============================================================================

    // ==============================================================================
    // 🟢 SECURE QR WALLET ACTIVATION & RELOAD (DATABASE-DRIVEN, SHORT ID FIX)
    // ==============================================================================
    
    // Hanapin ang mga pinaikling ID (QRN_ para sa new, QRR_ para sa reload)
    $stmt_qr_items = $pdo->prepare("SELECT product_id, price_per_item FROM booking_items WHERE booking_id = ? AND (product_id LIKE 'QRN_%' OR product_id LIKE 'QRR_%')");
    $stmt_qr_items->execute([$booking_id]);
    $qr_items = $stmt_qr_items->fetchAll(PDO::FETCH_ASSOC);

    $isNewQRCard = false; // <-- BAGONG DAGDAG (Tracker)

    foreach ($qr_items as $item) {
        $pid = $item['product_id'];
        $amount = (float)$item['price_per_item'];

        // --- 1. NEW CARD ACTIVATOR ---
        if (strpos($pid, 'QRN_') === 0) {
            $isNewQRCard = true; // <-- BAGONG DAGDAG
            $wallet_id = (int)str_replace('QRN_', '', $pid);
            
            $stmtW = $pdo->prepare("SELECT * FROM qr_wallets WHERE wallet_id = ?");
            $stmtW->execute([$wallet_id]);
            $wallet = $stmtW->fetch(PDO::FETCH_ASSOC);

            if ($wallet && $wallet['status'] === 'pending') {
                $stmt = $pdo->prepare("UPDATE qr_wallets SET status = 'active' WHERE wallet_id = ?");
                $stmt->execute([$wallet_id]);

                if (function_exists('sendQRWalletReceipt')) {
                    sendQRWalletReceipt($wallet['customer_email'], $wallet['customer_name'], $wallet['wallet_id'], $wallet['balance']);
                }
            }
        }
        // --- 2. RELOAD CARD ACTIVATOR ---
        elseif (strpos($pid, 'QRR_') === 0) {
            $wallet_id = (int)str_replace('QRR_', '', $pid);
            
            // Gamitin ang transaction_id at palitan ang 'reload' ng 'topup' para sa database compatibility
            $checkLog = $pdo->prepare("SELECT transaction_id FROM qr_transactions WHERE wallet_id = ? AND reference_id = ? AND transaction_type = 'topup'");
            $checkLog->execute([$wallet_id, $booking_id]);

            if ($checkLog->rowCount() === 0) {
                $stmt = $pdo->prepare("UPDATE qr_wallets SET balance = balance + ? WHERE wallet_id = ?");
                $stmt->execute([$amount, $wallet_id]);

                // Ginawang 'topup' ang transaction_type dahil ito lang ang valid value sa DB mo
                $logStmt = $pdo->prepare("INSERT INTO qr_transactions (wallet_id, transaction_type, points, description, reference_id) VALUES (?, 'topup', ?, 'Wallet Reload via Website/Kiosk', ?)");
                $logStmt->execute([$wallet_id, $amount, $booking_id]);
            }
        }
    }
    
    // Clear fallback sessions para malinis
    unset($_SESSION['pending_qr_wallet_id'], $_SESSION['reload_qr_wallet_id'], $_SESSION['reload_amount']);
    // ==============================================================================


    // ✅ TOP-UP FLOW: ADD ADD-ONS TO EXISTING BOOKING
    // =============================
    if ($isTopup) {
        
        if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
            
            $pdo->beginTransaction();

            // --- 🟢 BAGONG DAGDAG: Para sa Add-on Logs summary ---
            $log_summary_parts = [];

            // Prepare Statements for Upsert
            $stmtCheckItem = $pdo->prepare("SELECT quantity FROM booking_items WHERE booking_id=? AND product_id=? LIMIT 1");
            $stmtInsItem   = $pdo->prepare("INSERT INTO booking_items (booking_id, product_id, quantity, price_per_item) VALUES (?, ?, ?, ?)");
            $stmtUpdItem   = $pdo->prepare("UPDATE booking_items SET quantity = quantity + ?, price_per_item = ? WHERE booking_id=? AND product_id=?");

            $stmtUpsertRed = $pdo->prepare("
                INSERT INTO addon_redemptions (booking_id, product_id, unique_code, quantity_total, quantity_used, status) 
                VALUES (?, ?, ?, ?, 0, 'unused') 
                ON DUPLICATE KEY UPDATE 
                    quantity_total = quantity_total + VALUES(quantity_total), 
                    status = 'unused'
            ");

            foreach ($_SESSION['cart'] as $item) {
                $rawID = $item['id']; 
                
                $db_product_id = str_replace('prod_', '', $rawID);
                $db_product_id = str_replace('qr_topup_new_', 'QRN_', $db_product_id);
                $db_product_id = str_replace('qr_topup_reload_', 'QRR_', $db_product_id);
                $db_product_id = strtoupper($db_product_id);
                
                $qty   = (int)$item['quantity'];
                $price = (float)$item['price'];

                if ($qty <= 0) continue;

                // Idagdag sa log summary
                $log_summary_parts[] = ($item['name'] ?? $db_product_id) . " (x$qty)";

                // 1. Booking Items (Record Purchase)
                $stmtCheckItem->execute([$booking_id, $db_product_id]);
                $exists = $stmtCheckItem->fetchColumn();

                if ($exists !== false) {
                    $stmtUpdItem->execute([$qty, $price, $booking_id, $db_product_id]);
                } else {
                    $stmtInsItem->execute([$booking_id, $db_product_id, $qty, $price]);
                }

                // 2. Add-on Redemptions (Enable Scanning)
                $uniqueCode = $booking_id . '-' . $db_product_id;
                $stmtUpsertRed->execute([$booking_id, $db_product_id, $uniqueCode, $qty]);
            }

            // --- 🔴 ITO ANG PINAKA-IMPORTANTE: I-RECORD SA PURCHASE LOGS ---
            $log_summary = implode(", ", $log_summary_parts);
            $total_amt   = $_SESSION['total_price'] ?? 0;
            $created_by  = $is_kiosk ? 'Self-Service Kiosk' : 'Online Website';

            // BAGONG DAGDAG: I-check kung cash/pay at counter para gawing pending
            $is_cash = in_array(strtolower($payment_method), ['cash', 'pay_at_counter']);
            $topup_status = $is_cash ? 'pending' : 'paid';

            $stmtLog = $pdo->prepare("
                INSERT INTO addon_purchase_logs 
                (booking_id, items_summary, total_amount, payment_method, action, payment_status, created_by, created_at) 
                VALUES (?, ?, ?, ?, 'TOPUP', ?, ?, NOW())
            ");
            $stmtLog->execute([$booking_id, $log_summary, $total_amt, $payment_method, $topup_status, $created_by]);

            // Ibalik sa 'pending' ang buong booking para pumasok sa Reception Dashboard at masingil ng Cashier
            if ($is_cash) {
                $stmtRevert = $pdo->prepare("UPDATE bookings SET payment_status = 'pending' WHERE booking_id = ?");
                $stmtRevert->execute([$booking_id]);
            }
            // -------------------------------------------------------------

            $pdo->commit();

            // ✅ SEND EMAIL RECEIPT
            try {
                if (!empty($booking_details['customer_email']) && function_exists('sendAddonPurchaseReceipt')) {
                    $purchasedItems = [];
                    foreach ($_SESSION['cart'] as $item) {
                        $pid = strtoupper(str_replace('prod_', '', $item['id']));
                        $qty = (int)$item['quantity'];
                        $price = (float)$item['price'];
                        if ($qty <= 0) continue;

                        $stmtP = $pdo->prepare("SELECT name FROM products WHERE product_id=? LIMIT 1");
                        $stmtP->execute([$pid]);
                        $p = $stmtP->fetch(PDO::FETCH_ASSOC);
                        $name = $p ? $p['name'] : $pid;

                        $purchasedItems[] = [
                            'product_id' => $pid,
                            'name' => $name,
                            'quantity' => $qty,
                            'price' => $price,
                            'new_total' => null, 
                            'used' => null, 
                            'remaining' => null
                        ];
                    }

                    $processedBy = $is_kiosk ? 'Self-Service Kiosk' : 'Online Top-up';
                    
                    sendAddonPurchaseReceipt(
                        $booking_details['customer_email'],
                        $booking_details['customer_name'],
                        $booking_id,
                        $purchasedItems,
                        $payment_method, 
                        $processedBy
                    );
                }
            } catch (Throwable $e) {
                error_log("Topup receipt email failed: " . $e->getMessage());
            }

            // Clear TOPUP sessions
            unset($_SESSION['cart'], $_SESSION['total_price'], $_SESSION['topup_for_booking_id']);
        }
        
        if (!$is_kiosk) {
            header("Location: admin_verify_addons.php?booking_id=" . $booking_id . "&msg=topup_success");
            exit;
        }
    }
    // =============================
    // NORMAL BOOKING FLOW (New Bookings)
    // =============================
    
    // 2. MARK AS PAID IF PENDING
    $pay_method = strtolower(trim($booking_details['payment_method'] ?? ''));
    if ((($booking_details['payment_status'] ?? '') === 'pending') && !in_array($pay_method, ['cash', 'pay_at_counter'])) {
        $stmt = $pdo->prepare("UPDATE bookings SET payment_status = 'paid' WHERE booking_id = ?");
        $stmt->execute([$booking_id]);
        $booking_details['payment_status'] = 'paid';
    }

    // 3. SEND CONFIRMATION EMAIL (If not a top-up AND not pending cash)
    $is_pending_cash = (($booking_details['payment_method'] ?? '') === 'cash' && ($booking_details['payment_status'] ?? '') === 'pending');

    if (!$isTopup && $booking_details && !$is_pending_cash) {
        try {
            // Re-fetch items for email
            $stmt_items = $pdo->prepare("SELECT product_id, quantity, price_per_item as price FROM booking_items WHERE booking_id = ?");
            $stmt_items->execute([$booking_id]);
            $rawItems = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

            $items = [];
            foreach($rawItems as $row) {
                $name = "Unknown Item";
                $pid = $row['product_id'];

                if (strpos($pid, 'type_') === 0) {
                    $typeId = str_replace('type_', '', $pid);
                    $stmtT = $pdo->prepare("SELECT tt.category, tt.sub_label, p.name as package_name FROM ticket_types tt LEFT JOIN products p ON tt.product_id = p.product_id WHERE tt.type_id = ?");
                    $stmtT->execute([$typeId]);
                    $t = $stmtT->fetch();
                    if($t) {
                        $pkg = !empty($t['package_name']) ? $t['package_name'] . ' - ' : '';
                        $sub = !empty($t['sub_label']) ? ' (' . $t['sub_label'] . ')' : '';
                        $name = $pkg . $t['category'] . $sub;
                    }
                } else {
                    $stmtP = $pdo->prepare("SELECT name FROM products WHERE product_id = ?");
                    $stmtP->execute([$pid]);
                    $p = $stmtP->fetch();
                    if($p) $name = $p['name'];
                }

                $items[] = [
                    'product_id' => $pid,
                    'name' => $name,
                    'quantity' => $row['quantity'],
                    'price' => $row['price']
                ];
            }

            // --- FIX: Idagdag ang BUNDLE ADD-ONS (e.g. Meal Voucher) sa email items ---
            // Ang mga add-ons na kasama sa bundle ay nasa addon_redemptions lang, hindi sa booking_items
            $existingPids = array_column($items, 'product_id');
            
            $stmt_bundle_addons = $pdo->prepare("
                SELECT ar.product_id, ar.quantity_total AS quantity, p.name, p.price 
                FROM addon_redemptions ar 
                LEFT JOIN products p ON ar.product_id = p.product_id 
                WHERE ar.booking_id = ?
            ");
            $stmt_bundle_addons->execute([$booking_id]);
            $bundleAddons = $stmt_bundle_addons->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($bundleAddons as $arow) {
                $apid = $arow['product_id'];
                
                // Skip kung nasa booking_items na (avoid duplicate rows)
                if (in_array($apid, $existingPids)) continue;
                
                $items[] = [
                    'product_id' => $apid,
                    'name' => $arow['name'] ?? $apid,
                    'quantity' => (int)$arow['quantity'],
                    'price' => 0  // Kasama na sa bundle price, kaya 0 para hindi mag-double-count
                ];
            }

            $isAnnualPass = !empty($booking_details['expiry_date']);
            $cashierName = $is_kiosk ? "Self-Service Kiosk" : "Website";

            if ($isAnnualPass) {
                sendBookingConfirmation(
                    $booking_details['customer_email'],
                    $booking_details['customer_name'],
                    $booking_id,
                    $booking_details,
                    $items
                );
            } else {
                sendEntryReceipt(
                    $booking_details['customer_email'],
                    $booking_details['customer_name'],
                    $booking_id,
                    $booking_details,
                    $items,
                    $cashierName
                );
            }

        } catch (Throwable $e) {
            $email_error = "Email Error: " . $e->getMessage();
        }
    }

    // 4. CLEAR ALL SESSIONS
    unset($_SESSION['cart']);
    unset($_SESSION['total_price']);
    unset($_SESSION['booking_date']);
    unset($_SESSION['discount_applied']);
    unset($_SESSION['expiry_date']);
    unset($_SESSION['is_annual_pass']);
    unset($_SESSION['guest']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    echo "System Error: " . $e->getMessage();
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmed - Ajman Water Park</title>
    <link rel="stylesheet" href="booking-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        .success-card { text-align: center; padding: 50px 30px; max-width: 500px; margin: 40px auto; }
        .success-icon { font-size: 5rem; color: #28a745; margin-bottom: 20px; }
        .ref-number { background: #f4f8fb; padding: 15px; border-radius: 10px; margin: 15px 0; color: #003B72; font-weight: 700; font-size: 1.2rem; border: 1px dashed #003B72; }
        .error-box { background: #fff3cd; color: #856404; padding: 10px; border-radius: 5px; margin-bottom: 20px; font-size: 0.8rem; border: 1px solid #ffeeba; }
        .qr-container { margin: 20px 0; }
        .qr-container img { border: 1px solid #eee; padding: 5px; border-radius: 5px; }

        .details-box { text-align:left; background:#fafafa; padding:20px; border-radius:10px; font-size:0.95rem; margin-bottom:20px; border-left: 5px solid #003B72; }
        .details-row { margin-bottom: 8px; display: flex; justify-content: space-between; }
        .details-label { color: #666; font-weight: 600; }
        .details-value { color: #333; font-weight: bold; text-align: right; }

        .validity-badge { background-color: #e8f5e9; color: #2e7d32; padding: 5px 10px; border-radius: 50px; font-size: 0.85rem; font-weight: bold; display: inline-block; margin-bottom: 10px; }

        .member-photo-box { width: 150px; height: 150px; margin: 0 auto 20px auto; position: relative; }
        .member-photo-box img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; border: 5px solid #28a745; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .check-badge { position: absolute; bottom: 5px; right: 5px; background: #28a745; color: white; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; border: 3px solid white; }
    </style>
</head>
<body>
    <div class="booking-wrapper">
        <div class="white-card success-card">
            <?php if (!empty($email_error)): ?>
                <div class="error-box">
                    <strong>Note:</strong> Payment successful, but email failed.<br>
                    <?php echo htmlspecialchars($email_error); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($booking_details['face_image_path'])): ?>
                <div class="member-photo-box">
                    <img src="uploads/faces/<?php echo htmlspecialchars($booking_details['face_image_path']); ?>" alt="Member Photo" onerror="this.style.display='none'; this.parentElement.innerHTML='<div class=\'success-icon\'><i class=\'fas fa-check-circle\'></i></div>';">
                    <div class="check-badge"><i class="fas fa-check"></i></div>
                </div>
            <?php elseif (strtolower(trim($booking_details['payment_method'] ?? '')) === 'cash'): ?>
                <div class="success-icon"><i class="fas fa-clock" style="color: #f59e0b;"></i></div>
            <?php else: ?>
                <div class="success-icon"><i class="fas fa-check-circle"></i></div>
            <?php endif; ?>

            <h1 style="color:#003B72; margin:0 0 10px 0;">
                <?php 
                    if (strtolower(trim($booking_details['payment_method'] ?? '')) === 'cash') {
                        echo 'Booking Reserved!';
                    } else {
                        echo $isTopup ? 'Add-ons Purchased!' : 'Payment Successful!'; 
                    }
                ?>
            </h1>
            <p style="color:#666; margin:0;">
                <?php 
                    if (strtolower(trim($booking_details['payment_method'] ?? '')) === 'cash') {
                        echo 'Your transaction is on hold. Please proceed to the cashier to pay.';
                    } else {
                        echo $isTopup ? 'Your items have been added to your booking.' : 'Thank you for booking with Ajman Water Park.'; 
                    }
                ?>
            </p>

            <div class="ref-number">
                BOOKING ID: #<?php echo str_pad($booking_id, 6, '0', STR_PAD_LEFT); ?>
            </div>

            <div class="logo-container" style="margin: 50px 0; text-align: center;">
                <img src="Images/awpemaillogo.png" alt="Ajman Water Park Logo" style="max-width: 180px; height: auto;">
            </div>

            <?php if ($booking_details): ?>
            <div class="details-box">
                <div style="text-align: center; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                    <?php if (!empty($booking_details['expiry_date'])): ?>
                        <span class="validity-badge"><i class="fas fa-crown"></i> ANNUAL PASS MEMBER</span>
                        <div style="color: #003B72; font-size: 1.1rem; font-weight: 800; margin-top: 5px;">
                            VALID UNTIL: <?php echo date("F d, Y", strtotime($booking_details['expiry_date'])); ?>
                        </div>
                    <?php else: ?>
                        <span style="color: #666; font-size: 0.9rem;">DATE OF VISIT</span>
                        <div style="color: #003B72; font-size: 1.1rem; font-weight: 800;">
                            <?php echo date("F d, Y", strtotime($booking_details['visit_date'])); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="details-row">
                    <span class="details-label">Name:</span>
                    <span class="details-value"><?php echo htmlspecialchars($booking_details['customer_name']); ?></span>
                </div>

                <div class="details-row">
                    <span class="details-label">Email:</span>
                    <span class="details-value" style="font-size:0.85rem;"><?php echo htmlspecialchars($booking_details['customer_email']); ?></span>
                </div>

                <div class="details-row">
                    <span class="details-label">Amount Paid:</span>
                    <span class="details-value">AED <?php echo number_format($booking_details['total_amount'], 2); ?></span>
                </div>

               <div class="details-row">
                    <span class="details-label">Payment Method:</span>
                    <span class="details-value">
                        <?php 
                            if ($payment_method === 'qr_points') {
                                echo 'QR WALLET / POINTS';
                            } elseif ($payment_method === 'cash') {
                                echo 'CASH';
                            } elseif ($payment_method === 'card') {
                                echo 'CARD';
                            } else {
                                echo strtoupper($payment_method); 
                            }
                        ?>
                    </span>
                </div>
                
                <?php if (strtolower(trim($booking_details['payment_method'] ?? '')) === 'cash'): ?>
                    <div style="background-color: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin-top: 15px; text-align: center; border: 1px solid #ffeeba;">
                        <strong>⚠️ PAY AT THE COUNTER</strong><br>
                        Please present your Booking ID at the counter to pay in cash and receive your tickets.
                    </div>
                <?php endif; ?>

                <?php if ($booking_details['discount_code']): ?>
                    <div class="details-row" style="color:green;">
                        <span class="details-label" style="color:green;">Discount Code:</span>
                        <span class="details-value"><?php echo htmlspecialchars($booking_details['discount_code']); ?></span>
                    </div>
                <?php endif; ?>

                <?php if (isset($isNewQRCard) && $isNewQRCard): ?>
                    <div style="background-color: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-top: 15px; text-align: center; border: 1px solid #c3e6cb;">
                        <strong><i class="fas fa-id-card"></i> CLAIM YOUR PHYSICAL CARD AT THE COUNTER</strong><br>
                        Thank you!
                    </div>
                <?php endif; ?>

            </div>
            <?php endif; ?>

            <div class="action-section" style="margin-top: 30px; display: flex; flex-direction: column; gap: 15px; align-items: center;">
                <?php if ($is_kiosk && $booking_id): ?>
                    
                    <?php if (strtolower(trim($booking_details['payment_method'] ?? '')) === 'cash'): ?>
                        <div style="margin-bottom: 10px; font-size: 1rem; color: #555; text-align: center;">
                            Please take a photo of your <strong>Booking ID</strong><br>and proceed to the cashier to pay.
                        </div>
                        <a href="kiosk_home.php" style="width: 100%; max-width: 320px; text-decoration: none; padding: 15px; background: #003B72; color: white; border-radius: 50px; font-size: 1.1rem; font-weight: bold; text-align: center; display: block;">
                            <i class="fas fa-check-circle"></i> DONE / FINISH
                        </a>
                        
                        <?php else: ?>
                        <iframe src="print_receipt.php?booking_id=<?php echo $booking_id; ?>" 
                                style="position:absolute; width:0; height:0; border:0;" 
                                id="receiptFrame"></iframe>

                        <button onclick="printReceiptNow()" class="print-btn" style="width: 100%; max-width: 320px; padding: 20px; background: #28a745; color: white; border: none; border-radius: 50px; font-size: 1.4rem; font-weight: bold; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; box-shadow: 0 4px 15px rgba(40,167,69,0.3);">
                            <i class="fas fa-print"></i> PRINT RECEIPT
                        </button>

                        <a href="kiosk_home.php" style="width: 100%; max-width: 320px; text-decoration: none; padding: 15px; background: #003B72; color: white; border-radius: 50px; font-size: 1.1rem; font-weight: bold; text-align: center; display: block;">
                            <i class="fas fa-check-circle"></i> DONE / FINISH
                        </a>

                        <script>
                            function printReceiptNow() {
                                const frame = document.getElementById('receiptFrame');
                                if (frame) {
                                    const btn = document.querySelector('.print-btn');
                                    const originalText = btn.innerHTML;
                                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Printing...';
                                    btn.style.opacity = '0.7';

                                    frame.contentWindow.focus();
                                    frame.contentWindow.print();

                                    setTimeout(() => {
                                        btn.innerHTML = originalText;
                                        btn.style.opacity = '1';
                                    }, 3000);
                                }
                            }
                        </script>
                    <?php endif; ?>

                <?php else: ?>
                    <a href="index.php" class="ww-next-btn" style="text-decoration:none; display:inline-block; padding: 15px 30px; background: #003B72; color: white; border-radius: 50px; font-weight: bold; width: 100%; max-width: 320px; text-align: center; font-size: 1.1rem;">
                        <i class="fas fa-plus"></i> Book Another
                    </a>
                <?php endif; ?>
            </div>
        </div> 
    </div> 
</body>
</html>