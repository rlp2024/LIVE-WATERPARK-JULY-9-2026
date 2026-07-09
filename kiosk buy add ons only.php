<?php
// kiosk_buy_addons_only.php - ORIGINAL UI + SMART TICKET COUNTING + LOGIC UPDATE
ob_start();
session_start();
include_once 'db_connect.php';

$_SESSION['kiosk_mode'] = true;
// --- [LOGIC UPDATE] Set Source Marker ---
$_SESSION['last_step'] = 'kiosk_addons'; 
$is_kiosk = true;

// ---------------------------------------------------------
// 1. AJAX: VERIFY TICKET & GET SMART SUMMARY
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_booking') {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);

    $inputCode = trim($_POST['ticket_code']);
    
    // Clean URL
    if (strpos($inputCode, 'http') !== false) {
        $parts = explode('=', $inputCode);
        $inputCode = end($parts);
    }

    $bookingId = null;
    
    // Resolve Booking ID
    if (ctype_digit($inputCode)) {
        $bookingId = (int)$inputCode;
    } else {
        $stmt = $pdo->prepare("SELECT booking_id FROM ticket_instances WHERE ticket_code = ? LIMIT 1");
        $stmt->execute([$inputCode]);
        $bookingId = $stmt->fetchColumn();
        if (!$bookingId && preg_match('/^(\d+)-/', $inputCode, $matches)) {
            $bookingId = (int)$matches[1];
        }
    }

    if (!$bookingId) {
        echo json_encode(['status' => 'error', 'message' => 'Ticket not found.']);
        exit;
    }

    // Fetch Booking
    $stmtB = $pdo->prepare("SELECT * FROM bookings WHERE booking_id = ?");
    $stmtB->execute([$bookingId]);
    $booking = $stmtB->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Booking ID.']);
        exit;
    }

    if (strtolower($booking['payment_status']) !== 'paid') {
        echo json_encode(['status' => 'error', 'message' => 'Booking not paid.']);
        exit;
    }

    // --- SMART ITEM FETCHING LOGIC ---
    $sqlItems = "
        SELECT 
            bi.quantity,
            COALESCE(tt.category, p.name) as item_name
        FROM booking_items bi
        LEFT JOIN products p ON bi.product_id = p.product_id
        LEFT JOIN ticket_types tt ON bi.product_id = CONCAT('type_', tt.type_id)
        WHERE bi.booking_id = ? 
        AND (p.category_id != 6 OR p.category_id IS NULL)
    ";

    $stmtItems = $pdo->prepare($sqlItems);
    $stmtItems->execute([$bookingId]);
    $rows = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // Process and Clean Names
    $summaryHtml = "";
    $foundItems = false;

    foreach ($rows as $r) {
        $rawName = $r['item_name'];
        $qty = (int)$r['quantity'];
        
        if ($qty <= 0) continue;
        $foundItems = true;

        $displayName = $rawName;
        $icon = "fa-ticket-alt"; 

        if (stripos($rawName, 'Adult') !== false) {
            $displayName = "Adult";
            $icon = "fa-user";
        } elseif (stripos($rawName, 'Child') !== false || stripos($rawName, 'Junior') !== false) {
            $displayName = "Child";
            $icon = "fa-child";
        } elseif (stripos($rawName, 'Infant') !== false) {
            $displayName = "Infant";
            $icon = "fa-baby";
        } elseif (stripos($rawName, 'Annual Pass') !== false) {
            $icon = "fa-id-card";
        }

        $summaryHtml .= "
        <div style='display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #f0f0f0; padding:8px 0;'>
            <div style='display:flex; align-items:center; gap:10px; color:#555; font-size:1rem;'>
                <i class='fas $icon' style='color:#008CBA; width:20px; text-align:center;'></i>
                <span>$displayName</span>
            </div>
            <strong style='color:#003366; font-size:1.1rem;'>x$qty</strong>
        </div>";
    }

    if (!$foundItems) {
        $summaryHtml = "<div style='color:#999; font-style:italic;'>General Admission</div>";
    }

    // Set Session
    $_SESSION['topup_for_booking_id'] = $bookingId;
    $_SESSION['booking_date'] = date('Y-m-d'); 
    $_SESSION['guest'] = [
        'first_name' => $booking['customer_name'],
        'email'      => $booking['customer_email'],
        'phone'      => $booking['customer_phone'],
        'items_html' => $summaryHtml 
    ];

    echo json_encode(['status' => 'success']);
    exit;
}

// ---------------------------------------------------------
// 2. FETCH PRODUCTS (Add-ons Only)
// ---------------------------------------------------------
$sidebar_addons = [];
$meal_bundles = [];

try {
    $stmtA = $pdo->query("SELECT * FROM products WHERE category_id = 6 AND is_active = 1 ORDER BY name ASC");
    $sidebar_addons = $stmtA->fetchAll(PDO::FETCH_ASSOC);

    $stmtM = $pdo->query("SELECT p.* FROM products p JOIN product_categories pc ON p.category_id = pc.category_id WHERE pc.name = 'Meal Bundles' AND p.is_active = 1 ORDER BY p.name ASC");
    $meal_bundles = $stmtM->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$tickets_json = [];
$all_items = array_merge($sidebar_addons, $meal_bundles);
foreach ($all_items as $item) {
    $tickets_json['prod_' . $item['product_id']] = ['price' => (float)$item['price']];
}

function getIconForProduct($name) {
    $n = strtolower($name);
    if (strpos($n, 'parking') !== false) return 'fa-parking';
    if (strpos($n, 'zipline') !== false) return 'fa-wind';
    if (strpos($n, 'locker') !== false) return 'fa-lock';
    if (strpos($n, 'bridge') !== false) return 'fa-water';
    if (strpos($n, 'photo') !== false) return 'fa-camera';
    if (strpos($n, 'meal') !== false) return 'fa-utensils';
    return 'fa-ticket';
}

// Display Variables
$has_booking = isset($_SESSION['topup_for_booking_id']);
$guest_name = $_SESSION['guest']['first_name'] ?? 'Guest';
$booking_ref = $_SESSION['topup_for_booking_id'] ?? '---';
$items_display = $_SESSION['guest']['items_html'] ?? '';
$today_date = date('F d, Y');

$page_title = "Kiosk Add Ons";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

    <style>
    /* --- ORIGINAL UI STYLES --- */
    :root {
        --primary-blue: #003366;
        --accent-blue: #008CBA;
        --bg-color: #f0f4f8;
        --card-bg: #ffffff;
        --text-gray: #555;
        --border-radius: 20px;
    }

    body {
        font-family: 'Poppins', sans-serif;
        background-color: var(--bg-color);
        margin: 0;
        padding: 0;
        padding-bottom: 120px;
        user-select: none;
    }

    .kiosk-top-bar {
        width: 95%;
        max-width: 1200px;
        margin: 20px auto;
        display: flex;
        justify-content: flex-start;
    }

    .kiosk-top-bar.center {
        display: flex;
        justify-content: center;
        align-items: center;
        margin-top: 20px;
    }

    /* ONE FINAL STYLE FOR HOME BUTTON (no duplicates) */
    .kiosk-back-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        background: var(--primary-blue);
        color: #fff;
        padding: 12px 26px;
        border-radius: 50px;
        text-decoration: none;
        font-weight: 800;
        transition: 0.2s;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }
    .kiosk-back-btn:hover {
        opacity: 0.92;
        transform: translateY(-2px);
    }

    .booking-container {
        display: flex;
        flex-wrap: wrap;
        gap: 30px;
        max-width: 1200px;
        width: 95%;
        margin: 0 auto;
        align-items: flex-start;
        padding-top: 20px;
    }

    .left-column { flex: 2; min-width: 300px; }
    .right-column { flex: 1; min-width: 300px; position: sticky; top: 20px; height: fit-content; }

    .card {
        background: var(--card-bg);
        border-radius: var(--border-radius);
        padding: 25px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.05);
        margin-bottom: 30px;
    }

    .section-title {
        color: var(--primary-blue);
        margin-bottom: 15px;
        font-weight: 800;
        font-size: 1.3rem;
    }

    .upgrades-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 15px;
    }

    .upgrade-card {
        background: white;
        border-radius: var(--border-radius);
        padding: 20px;
        text-align: center;
        box-shadow: 0 5px 15px rgba(0,0,0,0.03);
        border: 2px solid transparent;
        transition: all 0.2s;
        position: relative;
        display: block;
        user-select: none;
    }
    .upgrade-card:hover { box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
    .upgrade-card.selected { border-color: var(--primary-blue); background: #f0fbff; }

    .upgrade-icons { font-size: 2rem; color: var(--accent-blue); margin-bottom: 10px; }
    .upgrade-card h4 { margin: 10px 0 5px 0; color: var(--primary-blue); font-size: 1rem; }
    .addon-desc { font-size: 0.8rem; color: #777; margin: 0 0 10px 0; }
    .upgrade-price { color: var(--primary-blue); font-weight: bold; display: block; margin-top: 5px; }

    .counter-box {
        display: flex;
        align-items: center;
        justify-content: center;
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 50px;
        padding: 5px;
        margin-top: 15px;
    }
    .counter-btn {
        background: none;
        border: none;
        font-size: 1.2rem;
        color: var(--primary-blue);
        cursor: pointer;
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .counter-value {
        font-weight: bold;
        min-width: 30px;
        text-align: center;
        border: none;
        font-size: 1rem;
        width: 40px;
        background: transparent;
    }

    .calendar-card {
        background: white;
        border-radius: var(--border-radius);
        padding: 25px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.05);
        text-align: left;
    }

    .info-label {
        font-size: 0.75rem;
        color: #999;
        text-transform: uppercase;
        font-weight: 800;
        letter-spacing: 0.5px;
        margin-bottom: 2px;
    }
    .info-data { font-size: 1.1rem; color: #333; font-weight: 700; margin-bottom: 15px; }
    .info-divider { height: 1px; background: #eee; margin: 10px 0 15px 0; }
    .items-box { background: #f9fbfd; padding: 15px; border-radius: 12px; border: 1px solid #eef2f7; }

    .ww-footer {
        position: fixed;
        bottom: 0;
        left: 0;
        width: 100%;
        background-color: #003B72;
        padding: 15px 0;
        z-index: 1000;
        box-shadow: 0 -5px 20px rgba(0,0,0,0.1);
    }
    .ww-footer-inner {
        max-width: 1000px;
        margin: 0 auto;
        padding: 0 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .ww-footer-left {
        color: white;
        font-weight: 700;
        font-size: 1.2rem;
        display: flex;
        flex-direction: column;
    }
    .ww-total-lbl { font-size: 0.85rem; opacity: 0.85; }
    .ww-total-val { font-size: 1.5rem; }

    .ww-footer-right { display: flex; align-items: center; gap: 15px; }

    .ww-next-btn {
        background: white;
        color: #003B72;
        border: none;
        padding: 12px 35px;
        font-weight: 800;
        border-radius: 30px;
        cursor: pointer;
        transition: 0.2s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .ww-next-btn:hover { background: #f0f0f0; transform: scale(1.05); }
    .ww-next-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

    /* MODAL */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.85);
        z-index: 9999;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(5px);
    }

    /* SINGLE FINAL modal-box style (no duplicates) */
    .modal-box {
        background: #32BFB6;
        width: 90%;
        max-width: 450px;
        padding: 30px;
        border-radius: 25px;
        text-align: center;
        position: relative;
        box-shadow: 0 20px 50px rgba(0,0,0,0.5);
        animation: popUp 0.3s ease-out;

        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 16px; /* equal spacing */
    }

    @keyframes popUp {
        from { transform: scale(0.8); opacity: 0; }
        to   { transform: scale(1); opacity: 1; }
    }

    /* Make main elements 90% width (no overrides later) */
    #reader {
        width: 90%;
        border-radius: 15px;
        overflow: hidden;
        background: #000;
        border: 4px solid #f0f0f0;
    }

    .manual-input {
        width: 90%;
        padding: 15px;
        font-size: 1.1rem;
        border: 2px solid #ddd;
        border-radius: 10px;
        text-align: center;
        box-sizing: border-box;
    }

    /* Back/Home button inside modal */
    .close-modal-btn {
        width: 30%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 14px 18px;
        border-radius: 12px;
        background: #FFD84D;
        color: #000 !important;
        text-decoration: none;
        font-weight: 800;
        cursor: pointer;
        transition: transform .05s ease, opacity .2s ease;
    }
    .close-modal-btn:hover { opacity: .70; }
    .close-modal-btn:active { transform: scale(.99); }
</style>



</head>

<body>

<div class="modal-overlay" id="scanModal" style="display: <?php echo $has_booking ? 'none' : 'flex'; ?>;">
    
    <div class="modal-box">
        <h2 style="color:#fff; margin:0;">Scan Your Main Ticket</h2>
        <p style="color:#fff; margin:0;">Scan QR Code to view upgrades</p>

        <div id="reader"></div>
        <div id="scanStatus" style="font-weight:bold; color:#FFD84D; height:20px;">Starting Camera...</div>

        <input type="text" class="manual-input" id="manualInput" placeholder="Or type Booking ID..." autocomplete="off">

        <a href="kiosk_home.php" class="close-modal-btn">Back to Home</a>
        </div>
        
    </div>

<form action="checkout.php" method="POST" id="booking-form">
    <div class="kiosk-top-bar center">
        <a href="kiosk_home.php" class="kiosk-back-btn">
  <i class="fas fa-home"></i> Home
</a>
    </div>

    <div class="booking-container">
        <div class="left-column">
            <?php if (!empty($meal_bundles)): ?>
                <div class="card">
                    <h3 class="section-title">Meal Bundles</h3>
                    <div class="upgrades-grid">
                        <?php foreach ($meal_bundles as $meal): ?>
                            <div class="upgrade-card" id="card-<?php echo $meal['product_id']; ?>">
                                <div class="upgrade-content">
                                    <div class="upgrade-icons"><i class="fas <?php echo getIconForProduct($meal['name']); ?>"></i></div>
                                    <h4><?php echo htmlspecialchars($meal['name']); ?></h4>
                                    <p class="addon-desc">Optional Upgrade</p>
                                    <span class="upgrade-price">AED <?php echo number_format($meal['price'], 0); ?></span>
                                </div>
                                <div class="counter-box">
                                    <button type="button" class="counter-btn qty-btn" data-action="minus" data-target="prod-<?php echo $meal['product_id']; ?>">−</button>
                                    <input type="number" name="tickets[<?php echo $meal['product_id']; ?>]" id="qty-prod-<?php echo $meal['product_id']; ?>" value="0" readonly class="counter-value qty-input">
                                    <button type="button" class="counter-btn qty-btn" data-action="plus" data-target="prod-<?php echo $meal['product_id']; ?>">+</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card">
                <h3 class="section-title">Add Ons</h3>
                <?php if (empty($sidebar_addons)): ?>
                    <p style="color:#666;margin:0;">No add-ons available right now.</p>
                <?php else: ?>
                    <div class="upgrades-grid">
                        <?php foreach ($sidebar_addons as $addon): ?>
                            <div class="upgrade-card" id="card-<?php echo $addon['product_id']; ?>">
                                <div class="upgrade-content">
                                    <div class="upgrade-icons"><i class="fas <?php echo getIconForProduct($addon['name']); ?>"></i></div>
                                    <h4><?php echo htmlspecialchars($addon['name']); ?></h4>
                                    <p class="addon-desc">Optional Upgrade</p>
                                    <span class="upgrade-price">AED <?php echo number_format($addon['price'], 0); ?></span>
                                </div>
                                <div class="counter-box">
                                    <button type="button" class="counter-btn qty-btn" data-action="minus" data-target="prod-<?php echo $addon['product_id']; ?>">−</button>
                                    <input type="number" name="tickets[<?php echo $addon['product_id']; ?>]" id="qty-prod-<?php echo $addon['product_id']; ?>" value="0" readonly class="counter-value qty-input">
                                    <button type="button" class="counter-btn qty-btn" data-action="plus" data-target="prod-<?php echo $addon['product_id']; ?>">+</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div style="height: 50px;"></div>
        </div>

        <div class="right-column">
            <div class="calendar-card">
                <?php if ($has_booking): ?>
                    <div style="text-align:center; margin-bottom:20px;">
                        <i class="fas fa-user-circle" style="font-size:3.5rem; color:var(--accent-blue);"></i>
                    </div>

                    <div class="info-label">Order ID</div>
                    <div class="info-data">#<?php echo $booking_ref; ?></div>

                    <div class="info-label">Date</div>
                    <div class="info-data"><?php echo $today_date; ?></div>

                    <div class="info-label">Customer Name</div>
                    <div class="info-data"><?php echo htmlspecialchars($guest_name); ?></div>

                    <div class="info-divider"></div>

                    <div class="info-label" style="margin-bottom:8px;">Existing Items</div>
                    <div class="items-box">
                        <?php echo $items_display; ?>
                    </div>

                <?php else: ?>
                    <div style="text-align:center; padding:30px 0; color:#ccc;">
                        <i class="fas fa-qrcode" style="font-size:3rem; margin-bottom:15px;"></i>
                        <p>Waiting for scan...</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <input type="hidden" id="booking_date" name="booking_date" value="<?php echo date('Y-m-d'); ?>">

    <div class="ww-footer">
        <div class="ww-footer-inner">
            <div class="ww-footer-left">
                <span class="ww-total-lbl">Total Amount</span>
                <strong class="ww-total-val" id="total-display">AED 0.00</strong>
            </div>
            <div class="ww-footer-right">
                <a href="kiosk_home.php" class="ww-next-btn" style="background:transparent; border:1px solid rgba(255,255,255,0.5); color:white;">HOME</a>
                <button type="submit" id="btn-next-step" class="ww-next-btn" disabled>
                    NEXT STEP <i class="fas fa-chevron-right" style="margin-left:8px;"></i>
                </button>
            </div>
        </div>
    </div>
</form>

<script>
    const ticketData = <?php echo json_encode($tickets_json); ?>;
    const hasBooking = <?php echo $has_booking ? 'true' : 'false'; ?>;

    // --- SCANNER LOGIC ---
    if (!hasBooking) {
        let html5QrcodeScanner = new Html5QrcodeScanner("reader", { fps: 10, qrbox: 250 }, false);
        html5QrcodeScanner.render(onScanSuccess, (err) => {});
        
        const manualInput = document.getElementById('manualInput');
        manualInput.focus();
        document.querySelector('.modal-box').addEventListener('click', () => manualInput.focus());

        let autoSubmitTimer = null;
        manualInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.keyCode === 13) {
                e.preventDefault();
                verifyTicket(this.value.trim());
            }
        });

        manualInput.addEventListener('input', function () {
            const value = this.value.trim();
            if (!value) return;
            clearTimeout(autoSubmitTimer);
            autoSubmitTimer = setTimeout(() => {
                verifyTicket(value);
            }, 400);
        });

        function onScanSuccess(decodedText) {
            if (!decodedText) return;
            manualInput.value = decodedText;
            verifyTicket(decodedText);
        }

        function verifyTicket(code) {
            const statusEl = document.getElementById('scanStatus');
            statusEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
            statusEl.style.color = "#008CBA";

            const formData = new FormData();
            formData.append('action', 'verify_booking');
            formData.append('ticket_code', code);

            fetch('?', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    statusEl.innerHTML = 'Success!';
                    statusEl.style.color = "green";
                    if(html5QrcodeScanner) html5QrcodeScanner.clear();
                    location.reload(); 
                } else {
                    statusEl.innerHTML = data.message;
                    statusEl.style.color = "red";
                    document.getElementById('manualInput').value = '';
                }
            })
            .catch(err => {
                statusEl.innerHTML = 'System Error';
                statusEl.style.color = "red";
            });
        }
    }

    // --- CART LOGIC ---
    document.addEventListener('DOMContentLoaded', () => {
        updateTotals();

        document.querySelectorAll('.qty-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.dataset.target;
                const input = document.getElementById(`qty-${targetId}`);
                let val = parseInt(input.value) || 0;

                if (this.dataset.action === 'plus') input.value = val + 1;
                if (this.dataset.action === 'minus' && val > 0) input.value = val - 1;

                updateTotals();
            });
        });
    });

    function updateTotals() {
        const totalEl = document.getElementById('total-display');
        const nextBtn = document.getElementById('btn-next-step');
        let total = 0;
        let hasItems = false;

        document.querySelectorAll('.qty-input').forEach(inp => {
            let rawId = inp.id.replace('qty-', ''); 
            let qty = parseInt(inp.value) || 0;
            let jsonKey = rawId.replace('-', '_'); 

            if (qty > 0 && ticketData[jsonKey]) {
                total += qty * ticketData[jsonKey].price;
                hasItems = true;
            }

            const card = inp.closest('.upgrade-card');
            if (card) {
                if(qty > 0) card.classList.add('selected');
                else card.classList.remove('selected');
            }
        });

        totalEl.textContent = `AED ${total.toFixed(2)}`;

        if (hasItems && hasBooking) {
            nextBtn.disabled = false;
            nextBtn.style.opacity = "1";
            nextBtn.style.cursor = "pointer";
        } else {
            nextBtn.disabled = true;
            nextBtn.style.opacity = "0.5";
            nextBtn.style.cursor = "not-allowed";
        }
    }
</script>

<!--?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/kiosk_fullscreen_include.php'; ?>-->

</body>
</html>