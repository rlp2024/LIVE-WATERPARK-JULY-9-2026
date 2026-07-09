<?php

session_start();
include_once 'db_connect.php';

/* ---------------------------------------------------------
1. KIOSK MODE & LOGIC (FIXED: Smart Switcher)
--------------------------------------------------------- */
if (isset($_GET['mode'])) {
    // Kung explicitly may ?mode=kiosk o ?mode=web sa URL, i-set ito.
    $_SESSION['kiosk_mode'] = ($_GET['mode'] === 'kiosk');
} elseif (!isset($_SESSION['kiosk_mode'])) {
    // Kung fresh load at walang existing na session, i-default sa false (Web mode)
    $_SESSION['kiosk_mode'] = false;
}

// Kunin ang final value
$is_kiosk = isset($_SESSION['kiosk_mode']) && $_SESSION['kiosk_mode'];
// =========================================================
// ✅ AJAX API: KUKUNIN ANG BALANCE KAPAG NAG-SCAN O NAG-TYPE
// =========================================================
if (isset($_GET['ajax_check_qr'])) {
    header('Content-Type: application/json');
    $qr = trim($_GET['qr_code']);
    
    $wallet_id_search = 0;
    if (preg_match('/^(W-)?(\d+)$/i', $qr, $matches)) {
        $wallet_id_search = (int)$matches[2];
    }
    
    $stmt = $pdo->prepare("SELECT * FROM qr_wallets WHERE qr_code = ? OR wallet_id = ? LIMIT 1");
    $stmt->execute([$qr, $wallet_id_search]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($wallet) {
        echo json_encode([
            'success' => true, 
            'name' => $wallet['customer_name'], 
            'email' => $wallet['customer_email'],
            'phone' => $wallet['phone'],
            'balance' => number_format($wallet['balance'], 2), 
            'status' => ucfirst($wallet['status'])
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'QR Card not found!']);
    }
    exit;
}

// =========================================================
// KUNG NAG-SUBMIT ANG FORM (DIREKTA NA SA PROCESS.PHP O SUCCESS)
// =========================================================
$show_success_modal = false;
$success_booking_id = 0;
$success_amount = 0;
$success_vat = 0;
$success_total = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = floatval($_POST['topup_amount']);
    $topup_type = $_POST['topup_type'] ?? 'new';
    $payment_method = $_POST['payment_method'] ?? 'card';
    
    // ✅ 5% VAT CALCULATION
    $vat_amount = $amount * 0.05;
    $grand_total = $amount + $vat_amount;

    try {
        if ($topup_type === 'new') {
            $first_name = trim($_POST['first_name']);
            $last_name = trim($_POST['last_name']);
            $email = trim($_POST['email']);
            $phone = trim($_POST['phone']);
            $customer_name = $first_name . ' ' . $last_name;

            $unique_string = 'AWP-QR-' . time() . '-' . strtoupper(substr(md5(uniqid()), 0, 4));

            $stmt = $pdo->prepare("INSERT INTO qr_wallets (qr_code, customer_name, customer_email, phone, balance, status) VALUES (?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$unique_string, $customer_name, $email, $phone, $amount]);
            $wallet_id = $pdo->lastInsertId();

            $_SESSION['pending_qr_wallet_id'] = $wallet_id;
            $_SESSION['guest'] = [
                'first_name' => $first_name, 'last_name' => $last_name,
                'email' => $email, 'phone' => $phone,
                'country' => 'UAE', 'are_you' => 'visitor'
            ];

            $_SESSION['cart'] = [
                'qr_topup' => [
                    'id' => 'qr_topup_new_' . $wallet_id,
                    'name' => 'New QR Wallet Card (' . $amount . ' AED Load)',
                    'price' => $amount, 'quantity' => 1, 'subtotal' => $amount
                ]
            ];

       } elseif ($topup_type === 'reload') {
            $scanned_code = trim($_POST['existing_qr_code']);
            $wallet_id_search = 0;
            if (preg_match('/^(W-)?(\d+)$/i', $scanned_code, $matches)) {
                $wallet_id_search = (int)$matches[2];
            }
            
            $stmtW = $pdo->prepare("SELECT * FROM qr_wallets WHERE qr_code = ? OR wallet_id = ? LIMIT 1");
            $stmtW->execute([$scanned_code, $wallet_id_search]);
            $wallet = $stmtW->fetch(PDO::FETCH_ASSOC);

            if (!$wallet) {
                die("<script>alert('Error: QR Card not found in the system!'); window.history.back();</script>");
            }

            $_SESSION['reload_qr_wallet_id'] = $wallet['wallet_id'];
            $_SESSION['reload_amount'] = $amount;

            $name_parts = explode(' ', $wallet['customer_name']);
            $f_name = $name_parts[0];
            $l_name = isset($name_parts[1]) ? $name_parts[1] : '';

            $_SESSION['guest'] = [
                'first_name' => $f_name, 'last_name' => $l_name,
                'email' => $wallet['customer_email'], 'phone' => $wallet['phone'],
                'country' => 'UAE', 'are_you' => 'visitor'
            ];

            $_SESSION['cart'] = [
                'qr_topup' => [
                    'id' => 'qr_topup_reload_' . $wallet['wallet_id'],
                    'name' => 'QR Wallet Reload (' . $amount . ' AED Load)',
                    'price' => $amount, 'quantity' => 1, 'subtotal' => $amount
                ]
            ];
        }

        // ✅ ISASAVE NA NATIN SA SESSION ANG WITH VAT NA TOTAL
        $_SESSION['original_total'] = $amount;
        $_SESSION['vat_amount'] = $vat_amount; 
        $_SESSION['total_price'] = $grand_total; 
        
        // KUNG CASH, REKTA SUCCESS MODAL
        if ($payment_method === 'cash') {
            $b_name = trim($_SESSION['guest']['first_name'] . ' ' . $_SESSION['guest']['last_name']);
            $b_email = !empty($_SESSION['guest']['email']) ? $_SESSION['guest']['email'] : 'kiosk@ajman.com';
            $b_phone = !empty($_SESSION['guest']['phone']) ? $_SESSION['guest']['phone'] : '0000000000';
            
            $stmtB = $pdo->prepare("INSERT INTO bookings (customer_name, customer_email, customer_phone, total_amount, payment_method, payment_status) VALUES (?, ?, ?, ?, 'cash', 'pending')");
            $stmtB->execute([$b_name, $b_email, $b_phone, $grand_total]);
            $booking_id = $pdo->lastInsertId();

            // ========================================================
            // ✅ BAGONG DAGDAG: I-save ang QR Top-up sa booking_items
            // ========================================================
            if (!empty($_SESSION['cart'])) {
                $stmtItem = $pdo->prepare("INSERT INTO booking_items (booking_id, product_id, quantity, price_per_item) VALUES (?, ?, ?, ?)");
                
                // Set Product ID: QRN_ (New) or QRR_ (Reload) para mabasa nang tama sa Dashboard
                $wallet_id_to_save = ($topup_type === 'new') ? $_SESSION['pending_qr_wallet_id'] : $_SESSION['reload_qr_wallet_id'];
                $product_code = ($topup_type === 'new') ? 'QRN_' . $wallet_id_to_save : 'QRR_' . $wallet_id_to_save;

                // Isave sa database
                $stmtItem->execute([
                    $booking_id,
                    $product_code,
                    1, // quantity
                    $amount // price na walang VAT (base price)
                ]);
            }
            // ========================================================
            
            $show_success_modal = true;
            $success_booking_id = $booking_id;
            $success_amount = $amount;
            $success_vat = $vat_amount;
            $success_total = $grand_total;
            
            unset($_SESSION['cart']);
            
        } else {
            // KUNG CARD, IPASA SA PROCESS.PHP KASAMA ANG VAT
            $kiosk_val = $is_kiosk ? '1' : '0'; // 🟢 FIX: Dynamic na ang is_kiosk value, hindi na hardcoded na 1!
            echo "<div style='text-align:center; padding: 100px; font-family: sans-serif;'>
                    <h2 style='color:#1e3a8a;'>Redirecting to Secure Payment...</h2>
                    <p>Please wait...</p>
                  </div>";
            echo "<form id='autoProcessForm' action='process.php' method='POST'>
                    <input type='hidden' name='payment_method' value='card'>
                    <input type='hidden' name='is_kiosk' value='$kiosk_val'>
                  </form>
                  <script>document.getElementById('autoProcessForm').submit();</script>";
            exit;
        }

    } catch (PDOException $e) {
        die("Error processing top-up: " . $e->getMessage());
    }
}

include_once 'header.php'; 
?>

<?php if ($is_kiosk): ?>
<div class="kiosk-top-bar cen">
    <a href="kiosk_home.php" class="kiosk-back-btn">
        <i class="fas fa-arrow-left"></i> Back to Home
    </a>
</div>

<style>
    header, .top-header, footer, .footer-section, #footer, nav, .navbar { display: none !important; }
    body { background-color: #f8fafc; margin: 0; padding-top: 0 !important; }

    .kiosk-top-bar {
        width: 100%; display: flex; justify-content: center; align-items: center;
        margin-top: 25px; margin-bottom: -15px; position: relative; z-index: 1000;
    }

    .kiosk-back-btn {
        display: inline-flex; align-items: center; gap: 10px; background-color: #333;
        color: #fff; padding: 10px 25px; border-radius: 50px; text-decoration: none;
        font-weight: bold; transition: 0.3s; box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        font-family: 'Poppins', sans-serif;
    }

    .kiosk-back-btn:hover { background-color: #000; transform: translateY(-2px); color: #fff; }
</style>
<?php endif; ?>

<style>
    .topup-wrapper { padding: 40px 15px; background-color: #f8fafc; min-height: 70vh; display: flex; justify-content: center; align-items: flex-start; }
    .wallet-card { background: #ffffff; padding: 25px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); max-width: 900px; width: 100%; border: 1px solid #e2e8f0; }
    .wallet-card h2 { margin-top: 0; color: #1e3a8a; font-size: 24px; font-weight: 800; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px; margin-bottom: 20px; text-align: center;}
    
    .topup-tabs { display: flex; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; }
    .tab-btn { flex: 1; padding: 12px 10px; text-align: center; font-weight: bold; cursor: pointer; color: #64748b; border-bottom: 3px solid transparent; transition: 0.3s; font-size: 15px;}
    .tab-btn.active { color: #0ea5e9; border-bottom-color: #0ea5e9; }
    
    .form-layout { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 20px;}
    .section-label { font-size: 14px; font-weight: 700; color: #475569; margin-bottom: 15px; display: block; text-transform: uppercase; letter-spacing: 0.5px; }
    
    .amounts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 12px; }
    .amount-btn { background: #ffffff; border: 2px solid #cbd5e1; color: #334155; padding: 15px 5px; border-radius: 12px; font-weight: 800; font-size: 16px; cursor: pointer; transition: all 0.2s ease; display: flex; justify-content: center; align-items: center; }
    .amount-btn:hover { border-color: #0ea5e9; color: #0ea5e9; background-color: #f0f9ff; }
    .amount-btn.active { background: #0ea5e9; border-color: #0ea5e9; color: #ffffff; box-shadow: 0 4px 12px rgba(14, 165, 233, 0.4); }
    
    .custom-amount-wrapper { display: flex; align-items: center; background: #f8fafc; border: 2px solid #cbd5e1; border-radius: 12px; padding: 0 12px; overflow: hidden; transition: border-color 0.2s; grid-column: 1 / -1; margin-top: 5px; }
    .custom-amount-wrapper:focus-within { border-color: #0ea5e9; background: #fff; }
    .custom-amount-wrapper span { font-weight: 800; color: #64748b; padding-right: 10px; font-size: 14px;}
    .custom-amount-wrapper input { border: none; background: transparent; padding: 15px 0; font-size: 15px; width: 100%; outline: none; color: #0f172a; font-weight: 800; }
    #amount-error { color: #ef4444; font-size: 13px; margin-top: 8px; display: none; font-weight: 600; }

    .details-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; }
    .input-group { margin-bottom: 15px; }
    .input-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 15px; }
    .form-input { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; transition: border-color 0.2s; outline: none; background: #fff; box-sizing: border-box;}
    .form-input:focus { border-color: #0ea5e9; box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1); }
    
    .submit-btn { width: 100%; background: #1e3a8a; color: white; border: none; padding: 15px; font-size: 15px; font-weight: 800; border-radius: 8px; cursor: pointer; transition: background 0.2s; margin-top: 15px; text-transform: uppercase; letter-spacing: 1px; }
    .submit-btn:hover { background: #172554; }

    .reload-section { display: none; text-align: center;}
    .qr-scan-box { border: 2px dashed #0ea5e9; padding: 15px; border-radius: 12px; background: #f0f9ff; margin-bottom: 15px;}
    #qr-reader { width: 100%; max-width: 100%; margin: 0 auto 15px auto; border-radius: 8px; overflow: hidden;}
    #qr-reader video { max-width: 100% !important; height: auto !important;}

    .card-info-display { background: #f0fdf4; border: 1px solid #bbf7d0; padding: 15px; border-radius: 10px; margin-top: 15px; text-align: left; display: none; font-size: 14px;}
    .card-info-display h4 { margin: 0 0 10px 0; color: #166534; font-weight: bold; font-size: 16px;}
    .info-row { display: flex; justify-content: space-between; margin-bottom: 5px; flex-wrap: wrap; word-break: break-all;}
    .info-row span:first-child { color: #166534; font-weight: bold; margin-right: 10px;}
    .info-row span:last-child { color: #15281e; font-weight: 900; text-align: right;}

    /* POP-UP MODAL STYLES */
    .checkout-modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); display: none; justify-content: center; align-items: center; z-index: 9999; backdrop-filter: blur(5px); }
    .checkout-modal { background: #fff; width: 90%; max-width: 400px; border-radius: 16px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); animation: popIn 0.3s ease; }
    @keyframes popIn { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    .checkout-modal h3 { margin-top: 0; color: #1e3a8a; text-align: center; font-size: 22px; margin-bottom: 5px;}
    .checkout-modal p.sub { text-align: center; color: #64748b; font-size: 13px; margin-bottom: 20px;}
    .checkout-total { text-align: center; margin-bottom: 20px; }
    .pay-opt { border: 2px solid #e2e8f0; border-radius: 12px; padding: 15px; margin-bottom: 12px; cursor: pointer; display: flex; align-items: center; transition: 0.2s; }
    .pay-opt.selected { border-color: #0ea5e9; background: #f0f9ff; }
    .pay-opt input { display: none; }
    .pay-opt-icon { font-size: 24px; margin-right: 15px; color: #64748b; transition: 0.2s; }
    .pay-opt.selected .pay-opt-icon { color: #0ea5e9; }
    .pay-opt-text { font-weight: bold; font-size: 15px; color: #334155; }
    .modal-btns { display: flex; gap: 15px; margin-top: 25px; }
    .btn-cancel { flex: 1; padding: 12px; border: none; background: #e2e8f0; color: #475569; font-weight: bold; border-radius: 8px; cursor: pointer; transition: 0.2s;}
    .btn-cancel:hover { background: #cbd5e1; }
    .btn-pay { flex: 1; padding: 12px; border: none; background: #1e3a8a; color: white; font-weight: bold; border-radius: 8px; cursor: pointer; transition: 0.2s; }
    .btn-pay:hover { background: #172554; }

    @media (max-width: 768px) {
        .topup-wrapper { padding: 20px 10px; }
        .wallet-card { padding: 20px 15px; }
        .form-layout { grid-template-columns: 1fr; gap: 20px; }
        .input-grid { grid-template-columns: 1fr; } 
    }
</style>

<main class="topup-wrapper">
    <div class="wallet-card">
        <h2>Top-up Your QR Wallet</h2>
        
        <div class="topup-tabs">
            <div class="tab-btn active" onclick="switchTab('new')"><i class="fas fa-plus-circle"></i> New Card</div>
            <div class="tab-btn" onclick="switchTab('reload')"><i class="fas fa-sync-alt"></i> Reload Existing Card</div>
        </div>

        <form action="topup.php" method="POST" id="topupForm">
            <input type="hidden" name="topup_type" id="topup_type_input" value="new">
            
            <div style="margin-bottom: 25px; padding-bottom: 25px; border-bottom: 1px solid #e2e8f0;">
                <label class="section-label" style="text-align:center;">Select Top Up Amount</label>
                <div class="amounts-grid" style="max-width: 600px; margin: 0 auto;">
                    <button type="button" class="amount-btn" data-val="50">50</button>
                    <button type="button" class="amount-btn" data-val="100">100</button>
                    <button type="button" class="amount-btn" data-val="200">200</button>
                    <button type="button" class="amount-btn" data-val="500">500</button>
                    <button type="button" class="amount-btn" data-val="1000">1000</button>
                    <div class="custom-amount-wrapper">
                        <span>Other:</span>
                        <input type="number" id="custom_amount" placeholder="Amount" min="50" step="any">
                    </div>
                </div>
                <input type="hidden" name="topup_amount" id="final_topup_amount" value="">
                <div id="amount-error" style="text-align:center;">⚠️ Minimum load amount is 50 AED.</div>
            </div>

            <div class="form-layout">
                <div id="panel-new" class="details-box" style="grid-column: 1 / -1; max-width: 600px; margin: 0 auto; width: 100%;">
                    <label class="section-label" style="text-align:center;">Customer Details</label>
                    <div class="input-grid">
                        <input type="text" id="fn" name="first_name" class="form-input" placeholder="First Name" required>
                        <input type="text" id="ln" name="last_name" class="form-input" placeholder="Last Name" required>
                    </div>
                    <div class="input-group">
                        <input type="email" id="em" name="email" class="form-input" placeholder="Email Address" required>
                    </div>
                    <div class="input-group">
                        <input type="tel" id="ph" name="phone" class="form-input" placeholder="Phone Number" required>
                    </div>
                </div>

                <div id="panel-reload" class="details-box reload-section" style="grid-column: 1 / -1; max-width: 600px; margin: 0 auto; width: 100%;">
                    <label class="section-label">Scan or Enter ID Number</label>
                   <div class="qr-scan-box">
                    <button type="button" id="start-scan-btn" style="width: 100%; background: #0ea5e9; color: white; border: none; padding: 12px; font-size: 16px; font-weight: bold; border-radius: 8px; cursor: pointer; margin-bottom: 15px; display: flex; justify-content: center; align-items: center; gap: 8px;">
                        <i class="fas fa-camera"></i> START SCANNING
                    </button>
                    <button type="button" id="stop-scan-btn" style="width: 100%; background: #ef4444; color: white; border: none; padding: 12px; font-size: 16px; font-weight: bold; border-radius: 8px; cursor: pointer; margin-bottom: 15px; display: none; justify-content: center; align-items: center; gap: 8px;">
                        <i class="fas fa-times-circle"></i> STOP SCANNING
                    </button>
                    
                    <div id="qr-reader" style="width: 100%; max-width:300px; margin: 0 auto 15px auto; display: none; border-radius: 8px; overflow: hidden;"></div>
                    
                    <div style="text-align: center; font-weight: bold; color: #64748b; margin-bottom: 10px; font-size: 12px;">OR</div>

                    <input type="text" name="existing_qr_code" id="existing_qr_code" class="form-input" placeholder="Type ID Number Here" style="text-align:center; font-weight:bold; letter-spacing: 1px;">
                        
                        <div id="qr-details-container" class="card-info-display">
                            <h4><i class="fas fa-check-circle"></i> Card Found</h4>
                            <div class="info-row"><span>Name:</span><span id="display-name"></span></div>
                            <div class="info-row"><span>Email:</span><span id="display-email"></span></div>
                            <div class="info-row"><span>Phone:</span><span id="display-phone"></span></div>
                        <div class="info-row" style="margin-top: 5px; border-top: 1px solid #bbf7d0; padding-top: 5px;">
                                <span>Remaining Balance:</span><span id="display-balance" style="color:#0ea5e9; font-size:1.1rem;"></span>
                            </div>
                            <div id="scrollToAmountBtn" style="margin-top: 15px; text-align: center; color: #0ea5e9; font-weight: 800; border-top: 1px dashed #bbf7d0; padding-top: 12px; cursor: pointer; transition: 0.2s;" onmouseover="this.style.color='#0284c7'" onmouseout="this.style.color='#0ea5e9'">
                                <i class="fas fa-arrow-up"></i> Please select the amount above to top up.
                            </div>
                        </div>
                        
                        <div id="qr-error-msg" style="color:red; font-weight:bold; margin-top:10px; display:none;"></div>
                    </div>
                    <p style="font-size:13px; color:#64748b;">Kindly scan your card's QR code to validate.</p>
                </div>
            </div>

            <div style="max-width: 600px; margin: 0 auto;">
                <button type="submit" class="submit-btn" id="btn-proceed">Proceed to Checkout</button>
            </div>
        </form>
    </div>
</main>

<div class="checkout-modal-overlay" id="checkout-modal">
    <div class="checkout-modal">
        <h3>Payment Selection</h3>
        <p class="sub">Please select how you would like to pay.</p>
        
        <div class="checkout-total" id="modal-display-amount">
            </div>
        
        <label class="pay-opt selected" onclick="selectPayOpt(this)">
            <input type="radio" name="modal_payment_method" value="card" checked>
            <i class="fas fa-credit-card pay-opt-icon"></i>
            <span class="pay-opt-text">Credit / Debit Card</span>
        </label>
        
        <label class="pay-opt" onclick="selectPayOpt(this)">
            <input type="radio" name="modal_payment_method" value="cash">
            <i class="fas fa-money-bill-wave pay-opt-icon"></i>
            <span class="pay-opt-text">Pay at Counter (Cash)</span>
        </label>
        
        <div class="modal-btns">
            <button type="button" class="btn-cancel" onclick="closeCheckoutModal()">Cancel</button>
            <button type="button" class="btn-pay" onclick="submitTopup()">Confirm & Pay</button>
        </div>
    </div>
</div>

<?php if ($show_success_modal): ?>
<div class="checkout-modal-overlay" id="success-modal" style="display: flex;">
    <div class="checkout-modal" style="text-align: center;">
        <div style="font-size: 60px; color: #16a34a; margin-bottom: 10px;">
            <i class="fas fa-check-circle"></i>
        </div>
        <h3 style="color: #166534; font-size: 24px;">Top-up Saved!</h3>
        <p class="sub" style="font-size: 14px; margin-bottom: 20px;">Your request has been successfully recorded.</p>
        
        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 15px; border-radius: 10px; margin-bottom: 25px; text-align: left;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                <span style="color: #15281e; font-size: 14px;">Booking ID:</span>
                <strong style="color: #166534; font-size: 16px;">#<?php echo $success_booking_id; ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                <span style="color: #15281e; font-size: 14px;">Payment Method:</span>
                <strong style="color: #ea580c; font-size: 14px;">CASH</strong>
            </div>
            <hr style="border: 0; border-top: 1px dashed #bbf7d0; margin: 10px 0;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                <span style="color: #64748b; font-size: 14px;">Load Amount:</span>
                <span style="color: #334155; font-size: 14px;">AED <?php echo number_format($success_amount, 2); ?></span>
            </div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                <span style="color: #64748b; font-size: 14px;">VAT (5%):</span>
                <span style="color: #334155; font-size: 14px;">AED <?php echo number_format($success_vat, 2); ?></span>
            </div>
          <div style="display: flex; justify-content: space-between; margin-top: 10px; padding-top: 10px; border-top: 2px solid #bbf7d0;">
                <span style="color: #15281e; font-size: 18px; font-weight: bold;">Grand Total:</span>
                <strong style="color: #0ea5e9; font-size: 18px;">AED <?php echo number_format($success_total, 2); ?></strong>
            </div>
        </div>
        
        <p style="color: #15281e; font-size: 14px; margin-bottom: 20px;">Please proceed to the counter and present this ID to pay the Grand Total to activate your card.</p>

        <?php if (isset($topup_type) && $topup_type === 'new'): ?>
            <div style="background-color: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px; text-align: center; border: 1px solid #c3e6cb; font-size: 14px;">
                <strong><i class="fas fa-id-card"></i> CLAIM YOUR PHYSICAL CARD AT THE COUNTER.</strong><br>Thank you!
            </div>
        <?php endif; ?>
     <?php 
    // Mag-set tayo ng dynamic link base sa mode
    $finish_url = ($is_kiosk) ? 'kiosk_home.php' : 'index.php'; 
    $button_text = ($is_kiosk) ? 'FINISH & GO TO HOME' : 'FINISH & BACK TO WEBSITE';
?>
<button type="button" class="btn-pay" onclick="window.location.href='<?php echo $finish_url; ?>'" style="width: 100%; background: #16a34a; padding: 15px; font-size: 16px;">
    <?php echo $button_text; ?>
</button>
    </div>
</div>
<?php endif; ?>

<script src="https://unpkg.com/html5-qrcode"></script>
<script>
    document.getElementById('scrollToAmountBtn').addEventListener('click', function() {
        const amountSection = document.querySelector('.amounts-grid');
        amountSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
        amountSection.style.transition = "transform 0.2s ease, box-shadow 0.2s ease";
        amountSection.style.transform = "scale(1.02)";
        amountSection.style.boxShadow = "0 0 15px rgba(14, 165, 233, 0.4)";
        setTimeout(() => {
            amountSection.style.transform = "scale(1)";
            amountSection.style.boxShadow = "none";
        }, 500);
    });

    const tabBtns = document.querySelectorAll('.tab-btn');
    const panelNew = document.getElementById('panel-new');
    const panelReload = document.getElementById('panel-reload');
    const typeInput = document.getElementById('topup_type_input');
    
    const fn = document.getElementById('fn'); const ln = document.getElementById('ln');
    const em = document.getElementById('em'); const ph = document.getElementById('ph');
    const existingQrInput = document.getElementById('existing_qr_code');

    let html5QrCode = null; 
    let currentMinAmount = 50; 
    let isCameraRunning = false;

    function resetUI(isSuccess) {
        const startBtn = document.getElementById('start-scan-btn');
        startBtn.style.display = 'flex';
        
        if (isSuccess) {
            startBtn.innerHTML = '<i class="fas fa-camera"></i> SCAN AGAIN';
        } else {
            startBtn.innerHTML = '<i class="fas fa-camera"></i> START SCANNING';
        }
        
        document.getElementById('stop-scan-btn').style.display = 'none';
        document.getElementById('qr-reader').style.display = 'none';
    }

    function stopScanner(isSuccess = false) {
        if (html5QrCode && isCameraRunning) {
            html5QrCode.stop().then(() => {
                html5QrCode.clear(); 
                html5QrCode = null;  
                isCameraRunning = false;
                resetUI(isSuccess);
            }).catch(err => {
                console.log("Scanner stopping error", err);
                resetUI(isSuccess);
            });
        } else {
            resetUI(isSuccess);
        }
    }

    document.getElementById('start-scan-btn').addEventListener('click', function() {
        document.getElementById('qr-details-container').style.display = 'none';
        document.getElementById('qr-error-msg').style.display = 'none';
        document.getElementById('existing_qr_code').value = '';
        
        if (!html5QrCode) {
            html5QrCode = new Html5Qrcode("qr-reader");
        }
        
        if (isCameraRunning) return;

        document.getElementById('start-scan-btn').style.display = 'none';
        document.getElementById('stop-scan-btn').style.display = 'flex';
        document.getElementById('qr-reader').style.display = 'block';

        html5QrCode.start(
            { facingMode: "environment" }, 
            { fps: 10, qrbox: { width: 250, height: 250 } },
            (decodedText) => {
                stopScanner(true);
                checkScannedQR(decodedText);
            },
            (errorMessage) => { }
        ).then(() => {
            isCameraRunning = true;
        }).catch((err) => {
            alert("Camera error. Please ensure permissions are granted.");
            stopScanner(false);
        });
    });

    document.getElementById('stop-scan-btn').addEventListener('click', function() {
        stopScanner(false); 
    });

    function switchTab(type) {
        tabBtns.forEach(btn => btn.classList.remove('active'));
        if(type === 'new') {
            tabBtns[0].classList.add('active');
            panelNew.style.display = 'block';
            panelReload.style.display = 'none';
            typeInput.value = 'new';
            fn.required = true; ln.required = true; em.required = true; ph.required = true;
            existingQrInput.required = false;
            
            currentMinAmount = 50;
            document.getElementById('custom_amount').min = 50;
            document.getElementById('amount-error').innerText = '⚠️ Minimum load amount is 50 AED.';
            
            stopScanner(false); 
        } else {
            tabBtns[1].classList.add('active');
            panelNew.style.display = 'none';
            panelReload.style.display = 'block';
            typeInput.value = 'reload';
            fn.required = false; ln.required = false; em.required = false; ph.required = false;
            existingQrInput.required = true;

            setTimeout(() => { existingQrInput.focus(); }, 100);

            currentMinAmount = 20;
            document.getElementById('custom_amount').min = 20;
            document.getElementById('amount-error').innerText = '⚠️ Minimum load amount is 20 AED.';
        }
    }

    let scannerTimerTopup;
    existingQrInput.addEventListener('input', function(e) {
        clearTimeout(scannerTimerTopup); 
        const val = this.value.trim();
        if(val !== '') {
            scannerTimerTopup = setTimeout(() => { checkScannedQR(val); }, 500); 
        }
    });

    existingQrInput.addEventListener('keypress', function(e) {
        if(e.key === 'Enter') { e.preventDefault(); }
    });

    function checkScannedQR(decodedText) {
        existingQrInput.value = decodedText;
        
        document.getElementById('qr-details-container').style.display = 'none';
        
        const errorDiv = document.getElementById('qr-error-msg');
        errorDiv.style.display = 'block';
        errorDiv.style.color = '#0ea5e9'; 
        errorDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Fetching card details...';

        fetch('topup.php?ajax_check_qr=1&qr_code=' + encodeURIComponent(decodedText))
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                errorDiv.style.display = 'none'; 
                document.getElementById('display-name').innerText = data.name;
                document.getElementById('display-email').innerText = data.email ? data.email : 'N/A';
                document.getElementById('display-phone').innerText = data.phone ? data.phone : 'N/A';
                document.getElementById('display-balance').innerText = 'AED ' + data.balance;
                document.getElementById('qr-details-container').style.display = 'block';
            } else {
                errorDiv.style.color = 'red'; 
                errorDiv.innerText = '❌ ' + data.message;
                existingQrInput.value = ''; 
            }
        })
        .catch(err => {
            errorDiv.style.color = 'red';
            errorDiv.innerText = '❌ Error fetching details.';
        });
    }

    const presetBtns = document.querySelectorAll('.amount-btn');
    const customInput = document.getElementById('custom_amount');
    const finalAmountInput = document.getElementById('final_topup_amount');
    const form = document.getElementById('topupForm');
    const errorMsg = document.getElementById('amount-error');

    presetBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            presetBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            customInput.value = '';
            finalAmountInput.value = this.getAttribute('data-val');
            errorMsg.style.display = 'none';
        });
    });

    customInput.addEventListener('input', function() {
        presetBtns.forEach(b => b.classList.remove('active'));
        finalAmountInput.value = this.value;
        if(parseFloat(this.value) >= currentMinAmount) { errorMsg.style.display = 'none'; }
    });

    // ✅ POP-UP MODAL LOGIC WITH VAT CALCULATION
    form.addEventListener('submit', function(e) {
        e.preventDefault(); 

        const amount = parseFloat(finalAmountInput.value);
        if (isNaN(amount) || amount < currentMinAmount) {
            errorMsg.style.display = 'block';
            errorMsg.animate([{ transform: 'translateX(-5px)' }, { transform: 'translateX(5px)' }, { transform: 'translateX(0px)' }], { duration: 300 });
            return;
        }
        
        const vat = amount * 0.05;
        const grandTotal = amount + vat;
        
        // I-update ang modal text para ipakita ang breakdown
        document.getElementById('modal-display-amount').innerHTML = 
            `<div style="font-size:16px; color:#64748b; margin-bottom:5px;">Top-up Amount: <b>AED ${amount.toFixed(2)}</b></div>
             <div style="font-size:16px; color:#64748b; margin-bottom:10px;">VAT (5%): <b>AED ${vat.toFixed(2)}</b></div>
             <div style="font-size:32px; color:#0ea5e9; font-weight:900;">AED ${grandTotal.toFixed(2)}</div>`;
             
        document.getElementById('checkout-modal').style.display = 'flex';
    });

    function selectPayOpt(element) {
        document.querySelectorAll('.pay-opt').forEach(el => el.classList.remove('selected'));
        element.classList.add('selected');
        element.querySelector('input').checked = true;
    }

    function closeCheckoutModal() {
        document.getElementById('checkout-modal').style.display = 'none';
    }

    function submitTopup() {
        let selectedPay = document.querySelector('input[name="modal_payment_method"]:checked').value;
        
        let hiddenPay = document.getElementById('hidden-pay-method');
        if(!hiddenPay) {
            hiddenPay = document.createElement('input');
            hiddenPay.type = 'hidden';
            hiddenPay.name = 'payment_method';
            hiddenPay.id = 'hidden-pay-method';
            document.getElementById('topupForm').appendChild(hiddenPay);
        }
        hiddenPay.value = selectedPay;
        
        document.getElementById('checkout-modal').style.display = 'none';
        
        const payBtn = document.querySelector('.btn-pay');
        payBtn.innerText = "Processing...";
        payBtn.style.pointerEvents = "none";

        document.getElementById('topupForm').submit();
    }
</script>