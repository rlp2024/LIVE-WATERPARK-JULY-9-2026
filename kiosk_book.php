<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Kiosk-only ang page na ito — laging naka-ON ang kiosk mode
$_SESSION['kiosk_mode'] = true;
$is_kiosk = true;

include_once 'db_connect.php';

/* ---------------------------------------------------------
   2. HELPER FUNCTIONS
--------------------------------------------------------- */
function getSimpleCategory($dbName) {
    $n = strtolower($dbName);
    if (strpos($n, 'adult') !== false) return 'adult';
    if (strpos($n, 'child') !== false || strpos($n, 'kid') !== false) return 'child';
    if (strpos($n, 'infant') !== false) return 'infant';
    return 'addon';
}

function getProductVariations($pdo, $product_id) {
    $stmt = $pdo->prepare("SELECT * FROM ticket_types WHERE product_id = ? ORDER BY price DESC");
    $stmt->execute([$product_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getIconForProduct($name) {
    $n = strtolower($name);
    if (strpos($n, 'parking') !== false) return 'fa-parking';
    if (strpos($n, 'zipline') !== false) return 'fa-wind';
    if (strpos($n, 'locker') !== false) return 'fa-lock';
    if (strpos($n, 'bridge') !== false) return 'fa-bridge-water';
    if (strpos($n, 'photo') !== false) return 'fa-camera';
    if (strpos($n, 'meal') !== false) return 'fa-utensils';
    return 'fa-ticket-alt';
}

$preselect_id = $_GET['preselect'] ?? 'DP-RES';

$main_products = [];
$addon_tickets = [];
$sidebar_addons = [];
$page_title = "Book Your Visit";
$main_ticket_image = "Images/placeholder.webp";
$main_ticket_description = "Your ultimate waterpark experience starts here!";
$is_preselect = false;
/* ---------------------------------------------------------
   4. DATA FETCHING
--------------------------------------------------------- */
// Get selected date from POST/SESSION (default today)
$selected_date = $_GET['date'] ?? $_POST['booking_date'] ?? date('Y-m-d');

// Helper function: i-check kung available ang product sa date
function isProductAvailableOnDate($product, $date) {
    if (!empty($product['available_from']) && $date < $product['available_from']) return false;
    if (!empty($product['available_until']) && $date > $product['available_until']) return false;
    return true;
}
/* ---------------------------------------------------------
   Helper: track na product IDs na nai-add na para iwas duplicates
--------------------------------------------------------- */
$added_pids = [];

function addProductToMain(&$main_products, &$added_pids, $product, $pdo, $selected_date) {
    if (!$product || in_array($product['product_id'], $added_pids)) return;
    $product['variations'] = getProductVariations($pdo, $product['product_id']);
    $product['available_on_date'] = isProductAvailableOnDate($product, $selected_date);
    $main_products[] = $product;
    $added_pids[] = $product['product_id'];
}


// STEP 1: Preselect
$check_stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE product_id = ? AND is_active = 1");
$check_stmt->execute([$preselect_id]);
if ($check_stmt->fetchColumn() > 0) {
    $is_preselect = true;
    $stmt_cat = $pdo->prepare("SELECT p.product_id, p.name, p.price, p.category_id, pc.name as cat_name, pc.description as cat_desc, p.image_url, p.available_from, p.available_until FROM products p LEFT JOIN product_categories pc ON p.category_id = pc.category_id WHERE p.product_id = ? AND p.is_active = 1");
    $stmt_cat->execute([$preselect_id]);
    $details = $stmt_cat->fetch(PDO::FETCH_ASSOC);
    if ($details) {
        $page_title = $details['name'];
        $main_ticket_description = $details['cat_desc'] ?? $main_ticket_description;
        $main_ticket_image = $details['image_url'] ?? $main_ticket_image;
        addProductToMain($main_products, $added_pids, $details, $pdo, $selected_date);
    }
}

// STEP 2 (DYNAMIC): Lahat ng active na General Admission (category_id = 1)
// products. Bagong product na gawin sa admin (hal. "3 Hours Pass") ay
// awtomatikong lalabas dito - hindi na hardcoded ang product_id.
$stmt_main = $pdo->query("SELECT * FROM products WHERE category_id = 1 AND is_active = 1 ORDER BY price ASC");
foreach ($stmt_main->fetchAll(PDO::FETCH_ASSOC) as $prod) {
    if (empty($main_products)) { $page_title = $prod['name']; $main_ticket_image = $prod['image_url'] ?? $main_ticket_image; }
    addProductToMain($main_products, $added_pids, $prod, $pdo, $selected_date);
}

// STEP 5: Fallback
if (empty($main_products)) {
    $stmt = $pdo->query("SELECT * FROM products WHERE category_id = 1 AND is_active = 1 ORDER BY price ASC LIMIT 1");
    $fallback = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($fallback) { $page_title = $fallback['name']; $main_ticket_image = $fallback['image_url'] ?? $main_ticket_image; addProductToMain($main_products, $added_pids, $fallback, $pdo, $selected_date); }
}

// --- CUSTOM SORT FOR DP-FULL (FULL DAY PASS) ---
foreach ($main_products as &$product) {
    if ($product['product_id'] === 'DP-FULL' && !empty($product['variations'])) {
        usort($product['variations'], function($a, $b) {
            $weightA = 4;
            $weightB = 4;

            $catA = strtolower($a['category']);
            $catB = strtolower($b['category']);

            // Bigyan ng "bigat" (weight) base sa category
            if (strpos($catA, 'kid') !== false || strpos($catA, 'child') !== false) $weightA = 1;
            elseif (strpos($catA, 'adult') !== false && strpos($catA, 'with swim') !== false) $weightA = 2;
            elseif (strpos($catA, 'adult') !== false) $weightA = 3;

            if (strpos($catB, 'kid') !== false || strpos($catB, 'child') !== false) $weightB = 1;
            elseif (strpos($catB, 'adult') !== false && strpos($catB, 'with swim') !== false) $weightB = 2;
            elseif (strpos($catB, 'adult') !== false) $weightB = 3;

            if ($weightA == $weightB) return 0;
            return ($weightA < $weightB) ? -1 : 1;
        });
    }
}
unset($product);
// --- END CUSTOM SORT ---

// Meal Bundles
$first_main_cat = !empty($main_products) ? ($main_products[0]['cat_name'] ?? '') : '';
if ($first_main_cat !== 'Meal Bundles') {
    $stmt_addons = $pdo->query("
        SELECT p.* FROM products p 
        JOIN product_categories pc ON p.category_id = pc.category_id 
        WHERE pc.name = 'Meal Bundles' AND p.is_active = 1
    ");
    $raw_addons = $stmt_addons->fetchAll(PDO::FETCH_ASSOC);
    foreach ($raw_addons as $ad) {
        $ad['variations'] = getProductVariations($pdo, $ad['product_id']);
        $addon_tickets[] = $ad;
    }
}

/* ---------------------------------------------------------
   5. SIDEBAR ADD-ONS
--------------------------------------------------------- */
$hidden_sidebar_addon_ids = ['ADD1', 'ADD2', 'ADD3', 'ADD4', 'ADD5'];

if (!empty($hidden_sidebar_addon_ids)) {
    $placeholders = implode(',', array_fill(0, count($hidden_sidebar_addon_ids), '?'));
    $sql = "
        SELECT *
        FROM products
        WHERE category_id = 6
          AND is_active = 1
          AND product_id NOT IN ($placeholders)
    ";
    $stmt_sidebar = $pdo->prepare($sql);
    $stmt_sidebar->execute($hidden_sidebar_addon_ids);
} else {
    $stmt_sidebar = $pdo->query("SELECT * FROM products WHERE category_id = 6 AND is_active = 1");
}

$sidebar_addons = $stmt_sidebar->fetchAll(PDO::FETCH_ASSOC);

/* ---------------------------------------------------------
   6. PREPARE JSON FOR JS
--------------------------------------------------------- */
$tickets_json = [];
$all_items = array_merge($main_products, $addon_tickets, $sidebar_addons);

foreach ($all_items as $item) {
    if (!empty($item['variations'])) {
        foreach ($item['variations'] as $v) {
            $tickets_json['type_' . $v['type_id']] = [
                'price' => (float)$v['price'],
                'day_type' => $v['day_type'] ?? 'all'
            ];
        }
    }
    // Always include base product price (para sa bundles/checkbox items)
    $tickets_json['prod_' . $item['product_id']] = [
        'price' => (float)$item['price']
    ];
}

/* ---------------------------------------------------------
   7. CALENDAR CONTROLS + BOOKING COUNTS
--------------------------------------------------------- */
$calendar_date_controls = [];
$calendar_month_controls = [];
$calendar_booking_counts = [];

/*
|--------------------------------------------------------------------------
| MONTH CONTROLS
|--------------------------------------------------------------------------
*/
try {
    $stmt = $pdo->query("
        SELECT month_key, is_enabled, notes
        FROM calendar_month_controls
    ");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $calendar_month_controls[$row['month_key']] = [
            'is_enabled' => (int)$row['is_enabled'],
            'notes'      => $row['notes'] ?? ''
        ];
    }
} catch (Throwable $e) {
    $calendar_month_controls = [];
}

/*
|--------------------------------------------------------------------------
| DATE CONTROLS
|--------------------------------------------------------------------------
*/
try {
    $stmt = $pdo->query("
        SELECT control_date, is_enabled, max_bookings, notes
        FROM calendar_date_controls
    ");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $calendar_date_controls[$row['control_date']] = [
            'is_enabled'   => (int)$row['is_enabled'],
            'max_bookings' => ($row['max_bookings'] !== null ? (int)$row['max_bookings'] : null),
            'notes'        => $row['notes'] ?? ''
        ];
    }
} catch (Throwable $e) {
    $calendar_date_controls = [];
}

/*
|--------------------------------------------------------------------------
| CURRENT BOOKING COUNTS PER DATE
|--------------------------------------------------------------------------
| Uses:
| - bookings.visit_date
| - bookings.payment_status = 'paid'
| - ticket_instances for actual ticket count
*/
try {
    $stmt = $pdo->query("
        SELECT
            b.visit_date AS booking_day,
            COUNT(ti.ticket_id) AS total_bookings
        FROM bookings b
        INNER JOIN ticket_instances ti
            ON ti.booking_id = b.booking_id
        WHERE b.visit_date IS NOT NULL
          AND b.visit_date >= CURDATE()
          AND b.payment_status = 'paid'
        GROUP BY b.visit_date
        ORDER BY b.visit_date ASC
    ");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $calendar_booking_counts[$row['booking_day']] = (int)$row['total_bookings'];
    }
} catch (Throwable $e) {
    $calendar_booking_counts = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kiosk - <?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/css/intlTelInput.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/intlTelInput.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/utils.js"></script>
    <script src="https://unpkg.com/html5-qrcode"></script>
    <style>
.pricing-section .ticket-variation {
    flex: 1 1 0;
    min-width: 0;
    justify-content: space-between;
}
        :root {
            --primary-blue: #003B72;
            --accent-blue: #008CBA;
            --bg-color: #f4f8fb;
            --card-bg: #ffffff;
            --text-dark: #333333;
            --border-radius: 20px;
            --max-width: 1200px;
            --slots-good: #16a34a;
            --slots-good-bg: #ecfdf3;
            --slots-good-border: #bbf7d0;
            --slots-low: #d97706;
            --slots-low-bg: #fff7ed;
            --slots-low-border: #fed7aa;
            --slots-full: #dc2626;
            --slots-full-bg: #fff1f1;
            --slots-full-border: #fecaca;
            --slots-today-ring: rgba(0, 140, 186, 0.18);
            --premium-shadow: 0 12px 28px rgba(0,0,0,0.06);
            --premium-shadow-hover: 0 14px 28px rgba(0,59,114,0.10);
        }
        body {
            background: radial-gradient(circle at top left, rgba(0,140,186,0.05), transparent 22%),
                radial-gradient(circle at top right, rgba(0,59,114,0.05), transparent 18%), var(--bg-color);
            font-family: 'Montserrat', 'Segoe UI', sans-serif;
            margin: 0; padding: 0; padding-bottom: 120px;
        }
        .booking-container {
            display: flex; flex-wrap: wrap; gap: 30px; align-items: flex-start;
            max-width: var(--max-width); width: 95%; margin: 0 auto; padding-top: 20px;
        }
        .left-column { flex: 2; min-width: 300px; }
        .right-column { flex: 1; min-width: 360px; position: sticky; top: 20px; height: fit-content; z-index: 10; }
        .kiosk-top-bar { width: 95%; max-width: 1200px; margin: 20px auto; display: flex; justify-content: center; align-items: center; }
        .kiosk-back-btn {
            display: inline-flex; align-items: center; gap: 10px; background-color: #333;
            color: #fff; padding: 10px 25px; border-radius: 50px; text-decoration: none;
            font-weight: bold; transition: 0.3s; box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .kiosk-back-btn:hover { background-color: #000; transform: translateY(-2px); }

        .card, .calendar-card {
            background: rgba(255,255,255,0.96); backdrop-filter: blur(8px);
            border-radius: var(--border-radius); padding: 25px;
            box-shadow: var(--premium-shadow); margin-bottom: 20px; border: 1px solid #eef2f6;
        }
        .ticket-main { display: flex; gap: 20px; margin-bottom: 25px; }
        .ticket-img { width: 100px; height: 100px; border-radius: 15px; object-fit: cover; flex-shrink: 0; }
        .section-title { color: var(--primary-blue); margin-bottom: 15px; font-weight: 700; font-size: 1.2rem; margin-top: 0; }
        .ticket-info h2 { color: var(--primary-blue); margin: 0 0 10px 0; font-size: 1.5rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; }
        .ticket-info p { color: #666; font-size: 0.95rem; line-height: 1.6; margin: 5px 0; font-weight: 500; }
        .pricing-section { display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #eee; padding-top: 20px; flex-wrap: wrap; gap: 15px; }
        .price-item { display: flex; align-items: center; gap: 15px; }
        .price-label strong { display: block; color: var(--primary-blue); font-size: 1.1rem; }
        .price-label span { font-size: 0.75rem; color: #888; }
        .counter-box { display: flex; align-items: center; background: #fff; border: 1px solid #ddd; border-radius: 50px; padding: 5px; }
        .counter-btn { background: none; border: none; font-size: 1.2rem; color: var(--primary-blue); cursor: pointer; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; }
        .counter-value { font-weight: bold; min-width: 30px; text-align: center; border: none; font-size: 1rem; width: 40px; background: transparent; }
        .upgrades-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; }
        .upgrade-card { background: white; border-radius: var(--border-radius); padding: 20px; text-align: center; box-shadow: 0 5px 15px rgba(0,0,0,0.03); border: 2px solid transparent; cursor: pointer; transition: all 0.2s; position: relative; user-select: none; }
        .upgrade-card:hover { box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
        .upgrade-icons { font-size: 2rem; color: var(--accent-blue); margin-bottom: 10px; }
        .upgrade-card h4 { margin: 10px 0 5px 0; color: var(--primary-blue); font-size: 1rem; }
        .addon-desc { font-size: 0.8rem; color: #777; margin: 0 0 10px 0; }
        .upgrade-price { color: var(--primary-blue); font-weight: bold; display: block; margin-top: 5px; }

        /* Calendar styles */
        .calendar-card { padding: 22px; overflow: visible; position: relative; }
        .calendar-card::before { content: ''; position: absolute; inset: 0 auto auto 0; width: 100%; height: 88px; background: linear-gradient(135deg, rgba(0,140,186,0.07), rgba(0,59,114,0.04), transparent); pointer-events: none; }
        .calendar-top-head { position: relative; display: flex; align-items: center; justify-content: space-between; gap: 30px; margin-bottom: 16px; }
        .calendar-top-head h3 { margin: 0; color: var(--primary-blue); font-size: 1.15rem; font-weight: 800; }
        .calendar-top-head p { margin: 4px 0 0 0; color: #667085; font-size: 0.82rem; line-height: 1.4; }
        .calendar-live-badge { position: relative; display: inline-flex; align-items: center; justify-content: center; background: #eff8ff; color: #075985; border: 1px solid #dbeafe; border-radius: 999px; min-height: 34px; min-width: 130px; padding: 7px 14px; font-size: 0.72rem; font-weight: 700; line-height: 1; white-space: nowrap; overflow: hidden; text-align: center; }
        .calendar-live-badge span { display: block; width: 65%; text-align: center; pointer-events: none; }
        .calendar-live-badge i { position: absolute; left: 10px; top: 32%; transform: translateY(-50%); font-size: 0.80rem; color: #16a34a; animation: liveDotPulse 1.8s ease-in-out infinite; }
        .calendar-header { position: relative; display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; color: var(--primary-blue); font-weight: bold; }
        .calendar-header span { font-size: 1rem; font-weight: 800; letter-spacing: 0.2px; }
        .cal-nav { cursor: pointer; color: var(--accent-blue); background: #f7fbff; border: 1px solid #e1edf8; font-size: 1.05rem; width: 38px; height: 38px; border-radius: 50%; transition: 0.2s ease; }
        .cal-nav:hover { background: #eef6ff; transform: translateY(-1px); }
        .calendar-fade { animation: calendarFadeIn 0.22s ease; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); width: 100%; text-align: center; gap: 8px; }
        .day-name { color: var(--primary-blue); font-weight: 800; font-size: 0.75rem; margin-bottom: 8px; opacity: 0.9; }

        .cal-date { color: #ccc; padding: 8px 2px; font-size: 0.9rem; border-radius: 16px; min-height: 74px; min-width: 0; display: flex; flex-direction: column; align-items: center; justify-content: flex-start; position: relative; border: 1px solid transparent; transition: all 0.2s ease; }
        .cal-date.empty { background: transparent; min-height: 74px; }
        .cal-day-number { display: block; font-size: 1rem; font-weight: 800; line-height: 1.2; margin-top: 2px; }
        .cal-capacity { display: inline-flex; align-items: center; justify-content: center; margin-top: 8px; padding: 4px 8px; font-size: 0.62rem; line-height: 1; white-space: nowrap; border-radius: 999px; font-weight: 800; letter-spacing: 0.2px; border: 1px solid transparent; }
        .cal-date.active { color: #344054; cursor: pointer; background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%); border-color: #e5edf5; box-shadow: 0 3px 10px rgba(0,0,0,0.03); }
        .cal-date.active:hover { background-color: #f8fbff; color: var(--primary-blue); border-color: #cfe4ff; transform: translateY(-1px); box-shadow: var(--premium-shadow-hover); }
        .cal-date.today-ring { box-shadow: inset 0 0 0 2px var(--slots-today-ring), 0 3px 10px rgba(0,0,0,0.03); }
        .cal-date.selected { background: linear-gradient(180deg, #004382 0%, #003B72 100%) !important; color: white !important; border-color: var(--primary-blue) !important; box-shadow: 0 12px 24px rgba(0,59,114,0.18) !important; }
        .cal-date.selected .cal-day-number { color: white !important; }
        .cal-date.selected .cal-capacity { background: rgba(255,255,255,0.16) !important; color: white !important; border-color: rgba(255,255,255,0.26) !important; }
        .cal-date.disabled { background: #f3f4f6; color: #b8bcc3; cursor: not-allowed; text-decoration: line-through; border-color: #eceff3; }
        .cal-date.full { background: var(--slots-full-bg); color: var(--slots-full); cursor: not-allowed; border: 1px solid var(--slots-full-border); }
        .cal-date.good-slots .cal-capacity { color: var(--slots-good); background: var(--slots-good-bg); border-color: var(--slots-good-border); }
        .cal-date.low-slots .cal-capacity { color: var(--slots-low); background: var(--slots-low-bg); border-color: var(--slots-low-border); animation: lowSlotPulse 1.7s ease-in-out infinite; }
        .cal-date.full .cal-capacity { color: var(--slots-full); background: #fff5f5; border-color: var(--slots-full-border); }

        .calendar-legend { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 18px; padding-top: 16px; border-top: 1px solid #edf2f7; }
        .legend-item { display: flex; align-items: center; gap: 8px; font-size: 0.74rem; color: #475467; justify-content: center; background: #fafcff; border: 1px solid #eef3f8; border-radius: 12px; padding: 10px 8px; }
        .legend-dot { width: 11px; height: 11px; border-radius: 50%; flex-shrink: 0; }
        .legend-dot.good { background: var(--slots-good); box-shadow: 0 0 0 4px rgba(22,163,74,0.10); }
        .legend-dot.low { background: var(--slots-low); box-shadow: 0 0 0 4px rgba(217,119,6,0.10); }
        .legend-dot.full { background: var(--slots-full); box-shadow: 0 0 0 4px rgba(220,38,38,0.10); }
        .calendar-note { margin-top: 12px; font-size: 0.75rem; color: #667085; text-align: center; }
        .selected-date-summary { margin-top: 14px; padding: 14px; border-radius: 16px; background: linear-gradient(180deg, #fbfdff 0%, #f7fbff 100%); border: 1px solid #e5edf7; }
        .selected-date-summary .summary-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
        .selected-date-summary .summary-top strong { color: var(--primary-blue); font-size: 0.88rem; font-weight: 800; }
        .selected-date-summary .summary-status { font-size: 0.68rem; font-weight: 800; border-radius: 999px; padding: 5px 8px; border: 1px solid transparent; }
        .selected-date-summary .summary-status.good { color: var(--slots-good); background: var(--slots-good-bg); border-color: var(--slots-good-border); }
        .selected-date-summary .summary-status.low { color: var(--slots-low); background: var(--slots-low-bg); border-color: var(--slots-low-border); }
        .selected-date-summary .summary-status.full { color: var(--slots-full); background: var(--slots-full-bg); border-color: var(--slots-full-border); }
        .selected-date-summary .summary-status.none { color: #667085; background: #f8fafc; border-color: #e5e7eb; }
        .selected-date-summary .summary-main { color: #475467; font-size: 0.79rem; line-height: 1.5; }
        .selected-date-summary .summary-main strong { color: #0f172a; }

        /* Footer */
        .ww-footer { position: fixed; bottom: 0; left: 0; width: 100%; background-color: var(--primary-blue); padding: 15px 0; z-index: 1000; box-shadow: 0 -5px 20px rgba(0,0,0,0.1); }
        .ww-footer-inner { max-width: 1000px; margin: 0 auto; padding: 0 20px; display: flex; justify-content: space-between; align-items: center; }
        .ww-footer-left { color: white; display: flex; flex-direction: column; }
        .ww-total-lbl { font-size: 0.7rem; text-transform: uppercase; opacity: 0.8; }
        .ww-total-val { font-size: 1.5rem; font-weight: 700; }
        .ww-footer-right { display: flex; align-items: center; gap: 15px; }
        .ww-next-btn { background: white; color: var(--primary-blue); border: none; padding: 12px 35px; font-weight: 800; border-radius: 30px; cursor: pointer; transition: 0.2s; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap; }
        .ww-next-btn:hover { background: #f0f0f0; transform: scale(1.05); }
        .ww-next-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .ww-steps-container { display: flex; gap: 10px; align-items: center; }
        .ww-step-circle { width: 30px; height: 30px; background: rgba(255,255,255,0.2); border-radius: 50%; color: white; display: grid; place-items: center; font-size: 0.8rem; }
        .ww-step-circle.active { background: white; color: var(--primary-blue); font-weight: 700; }

        /* Bundle card */
        .bundle-card .qty-input.bundle-input:not([value="0"]) { color: #16a34a; font-weight: 800; }
        .extra-kids-card[style*="opacity: 1"] { border-color: #0ea5e9 !important; background: #e0f2fe !important; }

        /* Date-based filtering */
        .product-card.date-disabled { opacity: 0.4; pointer-events: none; position: relative; }
        .product-card.date-disabled .date-unavailable-notice { display: flex !important; align-items: center; gap: 8px; background: #fff3cd; border: 1px solid #ffc107; color: #856404; padding: 8px 14px; border-radius: 8px; font-size: 0.82rem; font-weight: 700; margin-bottom: 12px; }
        .product-card:not(.date-disabled) .date-unavailable-notice { display: none !important; }

        /* ===== MODAL STYLES ===== */
        .kiosk-modal-overlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center;
            animation: fadeInOverlay 0.2s ease;
        }
        .kiosk-modal-overlay.active { display: flex; }
        .kiosk-modal-box {
            background: #fff; border-radius: 20px; width: 95%; max-width: 900px;
            max-height: 90vh; overflow-y: auto; padding: 35px; position: relative;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3); animation: slideUp 0.3s ease;
        }
        .kiosk-modal-close {
            position: absolute; top: 15px; right: 20px; font-size: 1.5rem;
            cursor: pointer; color: #666; background: none; border: none; transition: 0.2s; z-index: 10;
        }
        .kiosk-modal-close:hover { color: #d9534f; transform: scale(1.2); }
        .kiosk-modal-box h2 { color: var(--primary-blue); margin: 0 0 20px 0; font-size: 1.4rem; }

        /* Two-column modal layout */
        .modal-two-col { display: flex; gap: 30px; }
        .modal-col-left { flex: 1.4; }
        .modal-col-right { flex: 1; min-width: 280px; background: #f8faff; border-radius: 16px; padding: 20px; border: 1px solid #e5edf7; }
        .modal-col-right h3 { color: var(--primary-blue); font-size: 1rem; margin: 0 0 15px 0; font-weight: 800; }

        /* Form inputs */
        .ww-input { width: 100%; padding: 12px 14px; border: 1px solid #ddd; border-radius: 10px; font-size: 1rem; box-sizing: border-box; outline: none; transition: 0.2s; font-family: inherit; }
        .ww-input:focus { border-color: var(--primary-blue); box-shadow: 0 0 0 3px rgba(0,59,114,0.08); }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 700; color: #333; margin-bottom: 5px; font-size: 0.9rem; }
        .form-row { display: flex; gap: 15px; }
        .form-row .form-group { flex: 1; }
        .iti { width: 100% !important; }
        .iti .iti__selected-flag { border-radius: 10px 0 0 10px; }
        .select2-container { width: 100% !important; }
        .select2-container--default .select2-selection--single { height: 46px; border: 1px solid #ddd; border-radius: 10px; padding: 8px 14px; font-size: 1rem; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 28px; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 44px; }
        .select2-dropdown { border-radius: 10px; border: 1px solid #ddd; box-shadow: 0 8px 24px rgba(0,0,0,0.1); z-index: 99999; }

        /* Autofill indicator */
        .autofill-loading { display: none; align-items: center; gap: 8px; padding: 8px 12px; background: #eff8ff; border: 1px solid #bae6fd; border-radius: 8px; margin-bottom: 10px; font-size: 0.8rem; color: #0369a1; }
        .autofill-loading.active { display: flex; }
        .autofill-flash { animation: flashGreen 0.6s ease; }
        @keyframes flashGreen { 0% { background: #d1fae5; } 100% { background: transparent; } }

        /* Modal buttons */
        .kiosk-modal-btn {
            width: 100%; padding: 14px; border: none; border-radius: 12px;
            font-size: 1.1rem; font-weight: 800; cursor: pointer; transition: 0.2s; margin-top: 10px; font-family: inherit;
        }
        .kiosk-modal-btn.primary { background: var(--primary-blue); color: white; }
        .kiosk-modal-btn.primary:hover { background: #002855; transform: translateY(-1px); }
        .kiosk-modal-btn.success { background: #16a34a; color: white; }
        .kiosk-modal-btn.success:hover { background: #15803d; }

        /* Payment options */
        .payment-option { background: #f9f9f9; border: 1px solid #ddd; padding: 15px; border-radius: 10px; display: flex; align-items: center; margin-bottom: 12px; cursor: pointer; transition: 0.2s; }
        .payment-option:hover { border-color: var(--primary-blue); background: white; }
        .payment-option input[type="radio"] { display: none; }
        .payment-option.selected-method { border-color: var(--primary-blue); background: #f0f7ff; }
        .radio-dot { width: 20px; height: 20px; border: 2px solid #ccc; border-radius: 50%; margin-right: 15px; position: relative; flex-shrink: 0; }
        .payment-option input:checked + .radio-dot { border-color: var(--primary-blue); }
        .payment-option input:checked + .radio-dot::after { content: ''; width: 10px; height: 10px; background: var(--primary-blue); border-radius: 50%; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); }

        /* QR scanner styles */
        .qr-inline-status { display: none; padding: 12px; border-radius: 10px; margin-top: 10px; font-size: 0.9rem; }
        .qr-inline-status.active { display: block; }
        .qr-inline-status.success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .qr-inline-status.error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .qr-scanner-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); z-index: 99999; align-items: center; justify-content: center; flex-direction: column; }
        .qr-scanner-overlay.active { display: flex; }
        .qr-scanner-box { background: white; border-radius: 20px; padding: 30px; max-width: 500px; width: 90%; text-align: center; }
        #qr-reader { width: 100%; border-radius: 12px; overflow: hidden; margin-bottom: 15px; }
        .qr-manual-input { display: flex; gap: 10px; margin-top: 15px; }
        .qr-manual-input input { flex: 1; padding: 12px; border: 1px solid #ddd; border-radius: 10px; font-size: 1rem; }

        /* Discount box */
        .discount-box { background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 15px; margin: 15px 0; }
        .discount-box h4 { margin: 0 0 10px 0; color: #92400e; font-size: 0.9rem; }
        .discount-row { display: flex; gap: 10px; }
        .discount-row input { flex: 1; padding: 10px 14px; border: 1px solid #ddd; border-radius: 8px; font-size: 0.95rem; }
        .discount-row button { padding: 10px 20px; background: var(--primary-blue); color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; white-space: nowrap; }
        .discount-applied { display: flex; align-items: center; justify-content: space-between; padding: 10px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; margin-top: 10px; }
        .discount-applied .remove-discount { color: #dc2626; cursor: pointer; font-weight: 700; }

        /* Checkout summary items */
        .checkout-item { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f0f0f0; font-size: 0.9rem; }
        .checkout-item:last-child { border-bottom: none; }
        .checkout-total-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 0.95rem; }
        .checkout-grand-total { display: flex; justify-content: space-between; font-size: 1.3rem; font-weight: 800; color: var(--primary-blue); border-top: 2px solid var(--primary-blue); padding-top: 15px; margin-top: 10px; }
        .guest-info-box { background: #f0f7ff; border: 1px solid #bae6fd; border-radius: 12px; padding: 15px; margin-bottom: 15px; font-size: 0.85rem; }
        .guest-info-box p { margin: 4px 0; color: #333; }

        /* Agreements box */
        .agreements-box { background: #f8faff; border: 1px solid #d0deea; padding: 15px; border-radius: 10px; margin-top: 15px; }
        .agreement-item { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 12px; }
        .agreement-item input[type="checkbox"] { width: 20px; height: 20px; cursor: pointer; margin-top: 2px; flex-shrink: 0; }
        .agreement-item label { font-size: 0.85rem; color: #444; line-height: 1.5; cursor: pointer; }
        .terms-link { color: var(--accent-blue); text-decoration: underline; cursor: pointer; }

        /* Terms sub-modal */
        .terms-sub-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 99999; align-items: center; justify-content: center; }
        .terms-sub-modal.active { display: flex; }
        .terms-sub-box { background: white; border-radius: 16px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto; padding: 30px; }
        .terms-sub-box h3 { color: var(--primary-blue); margin: 0 0 15px 0; }
        .terms-sub-box ol { padding-left: 20px; }
        .terms-sub-box li { margin-bottom: 12px; line-height: 1.6; color: #444; font-size: 0.92rem; }

        /* Success modal */
        .success-icon { font-size: 4rem; color: #16a34a; margin-bottom: 15px; animation: successPop 0.5s ease; }
        .success-booking-id { background: #f0fdf4; border: 2px dashed #16a34a; padding: 15px; border-radius: 12px; text-align: center; margin: 15px 0; }
        .success-booking-id strong { font-size: 1.5rem; color: var(--primary-blue); }

        /* Summary sidebar items */
        .summary-item { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #eef2f6; font-size: 0.85rem; }
        .summary-item:last-child { border-bottom: none; }
        .summary-item i { color: var(--accent-blue); width: 20px; text-align: center; }
        .summary-date-box { background: white; border: 1px solid #dbeafe; border-radius: 10px; padding: 12px; margin-bottom: 15px; display: flex; align-items: center; gap: 10px; }
        .summary-date-box i { color: var(--primary-blue); font-size: 1.2rem; }
        .summary-total-box { background: var(--primary-blue); color: white; border-radius: 10px; padding: 14px; margin-top: 15px; text-align: center; }
        .summary-total-box .amount { font-size: 1.4rem; font-weight: 800; }

        /* Animations */
        @keyframes fadeInOverlay { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes successPop { 0% { transform: scale(0); } 50% { transform: scale(1.2); } 100% { transform: scale(1); } }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes liveDotPulse { 0%, 100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.15); opacity: 0.75; } }
        @keyframes lowSlotPulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(217,119,6,0.14); transform: scale(1); } 50% { box-shadow: 0 0 0 6px rgba(217,119,6,0.04); transform: scale(1.02); } }
        @keyframes calendarFadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

        /* Responsive */
        @media (max-width: 900px) {
            .booking-container { flex-direction: column; }
            .ticket-main { flex-direction: column; }
            .ticket-img { width: 100%; height: auto; }
            .upgrades-grid { grid-template-columns: 1fr; }
            .pricing-section { flex-direction: column; align-items: stretch; }
            .ww-steps-container { display: none !important; }
            .right-column { min-width: 100%; position: static; }
            .calendar-legend { grid-template-columns: 1fr; }
            .modal-two-col { flex-direction: column; }
            .modal-col-right { min-width: unset; }
        }
    </style>
</head>
<body>


<div class="kiosk-top-bar">
    <a href="kiosk_home.php" class="kiosk-back-btn">
        <i class="fas fa-arrow-left"></i> Back to Home
    </a>
</div>

<div class="booking-container">
    <div class="left-column">

        <?php foreach ($main_products as $product): 
            $pid = $product['product_id'];
            $availFrom = $product['available_from'] ?? '';
            $availUntil = $product['available_until'] ?? '';
            $isFamPkg = ($pid === 'FAM-PKG');
        ?>
        <?php
            // Determine product day_type from its ticket variations
            $productDayType = 'all';
            if (!empty($product['variations'])) {
                $firstVarDayType = $product['variations'][0]['day_type'] ?? 'all';
                if ($firstVarDayType === 'weekend' || $firstVarDayType === 'weekday') {
                    $productDayType = $firstVarDayType;
                }
            }
        ?>
        <div class="card product-card" data-product-id="<?php echo $pid; ?>" data-available-from="<?php echo $availFrom; ?>" data-available-until="<?php echo $availUntil; ?>" data-day-type="<?php echo $productDayType; ?>">
            <div class="date-unavailable-notice" style="display:none;">
                <i class="fas fa-calendar-times"></i>
                <span>Not available on selected date</span>
            </div>
            <div class="ticket-main">
                <img src="<?php echo htmlspecialchars($product['image_url'] ?? 'Images/placeholder.webp'); ?>" alt="Ticket" class="ticket-img">
                <div class="ticket-info">
                    <h2><?php echo htmlspecialchars($product['name']); ?></h2>
                    <p><?php echo htmlspecialchars($main_ticket_description); ?></p>
                    
                    <?php if ($productDayType === 'weekend'): ?>
                        <p style="color: #d97706; font-size: 0.82rem; font-weight: 600; margin-top: 2px;">AVAILABLE ONLY FROM FRIDAYS THROUGH SUNDAYS</p>
                    <?php elseif ($productDayType === 'weekday'): ?>
                        <p style="color: #d97706; font-size: 0.82rem; font-weight: 600; margin-top: 2px;">AVAILABLE ONLY FROM MONDAYS THROUGH THURSDAYS</p>
                    <?php endif; ?>

                    <?php if ($isFamPkg): ?>
                        <small id="bundle-includes-top" style="color:#16a34a; font-weight:700;">Includes: 2 Adults + 2 Kids + 4 Combo Meals</small>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isFamPkg): ?>
            <div class="bundle-card" style="margin-top:5px; border:2px solid #2ecc71; border-radius:14px; padding:16px 18px; background:#f0fff4;">
                <div style="display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
                    <label style="display:flex; align-items:center; cursor:pointer; flex-shrink:0;">
                        <input type="checkbox" id="bundle-checkbox" class="bundle-checkbox" style="width:22px; height:22px; accent-color:#2ecc71; cursor:pointer;">
                    </label>
                    <div style="flex:1; min-width:200px;">
                        <div style="color:#27ae60; font-weight:800; font-size:1rem; text-transform:uppercase; display:flex; align-items:center; gap:8px;">
                            <i class="fas fa-gift"></i> FAMILY PACKAGE (BUNDLE)
                        </div>
                        <div id="bundle-price-display" data-base-price="<?php echo (float)$product['price']; ?>" style="color:#2d6a4f; font-weight:700; margin-top:4px; font-size:1.1rem;">AED <?php echo number_format($product['price'], 2); ?></div>
                        <div id="bundle-includes-text" style="color:#555; font-size:0.82rem; margin-top:2px;">With: 2 Adults + 2 Kids + 4 Combo Meals</div>
                    </div>
                    <div class="counter-box bundle-counter" style="background:#fff; opacity:0.4; pointer-events:none; transition: all 0.2s;">
                        <button type="button" class="counter-btn qty-btn" data-action="minus" data-target="prod-<?php echo $pid; ?>">&#8722;</button>
                        <input type="number" name="tickets[<?php echo $pid; ?>]" id="qty-prod-<?php echo $pid; ?>" value="0" readonly class="counter-value qty-input bundle-input" data-category="adult" data-bundle="1">
                        <button type="button" class="counter-btn qty-btn" data-action="plus" data-target="prod-<?php echo $pid; ?>">+</button>
                    </div>
                </div>
            </div>

            <?php 
            $kids_meal_type = null;
            foreach ($product['variations'] as $v) {
                if (stripos($v['category'], 'kids') !== false || stripos($v['category'], 'child') !== false) {
                    $kids_meal_type = $v; break;
                }
            }
            if ($kids_meal_type): ?>
            <div class="extra-kids-card" id="extra-kids-card" style="margin-top:12px; padding:12px 16px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:10px; opacity:0.5; pointer-events:none;">
                <div class="price-item" style="width:100%; justify-content:space-between;">
                    <div class="price-label">
                        <strong>AED <?php echo number_format($kids_meal_type['price'], 2); ?></strong>
                        <span>Extra Kids w/ Combo Meal <em style="color:#888; font-size:0.75rem;">(requires Family Package)</em></span>
                    </div>
                    <div class="counter-box">
                        <button type="button" class="counter-btn qty-btn" data-action="minus" data-target="type-<?php echo $kids_meal_type['type_id']; ?>">&#8722;</button>
                        <input type="number" name="tickets[<?php echo $kids_meal_type['type_id']; ?>]" id="qty-type-<?php echo $kids_meal_type['type_id']; ?>" value="0" readonly class="counter-value qty-input extra-kids-input" data-category="child" data-requires-bundle="1" data-unit-price="<?php echo (float)$kids_meal_type['price']; ?>">
                        <button type="button" class="counter-btn qty-btn" data-action="plus" data-target="type-<?php echo $kids_meal_type['type_id']; ?>">+</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php else: ?>
<!-- Regular ticket variations -->
<div class="pricing-section">
    <?php if (!empty($product['variations'])): ?>
        <?php foreach ($product['variations'] as $type): ?>
            <?php $simpleCategory = getSimpleCategory($type['category']); ?>
            <!-- NAGDAGDAG NG class at data-day-type para sa filtering ng JS -->
            <div class="price-item ticket-variation" data-day-type="<?php echo htmlspecialchars($type['day_type'] ?? 'all'); ?>">
                <div class="price-label">
                    <strong>AED <?php echo number_format($type['price'], 2); ?></strong>
                    <span><?php echo htmlspecialchars($type['category']); ?><?php if (!empty($type['sub_label'])) echo ' ' . htmlspecialchars($type['sub_label']); ?></span>
                </div>
                <div class="counter-box">
                    <button type="button" class="counter-btn qty-btn" data-action="minus" data-target="type-<?php echo $type['type_id']; ?>">&#8722;</button>
                    <input type="number" name="tickets[<?php echo $type['type_id']; ?>]" id="qty-type-<?php echo $type['type_id']; ?>" value="0" readonly class="counter-value qty-input" data-category="<?php echo $simpleCategory; ?>">
                    <button type="button" class="counter-btn qty-btn" data-action="plus" data-target="type-<?php echo $type['type_id']; ?>">+</button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
            <div class="price-item">
                        <div class="price-label">
                            <strong>AED <?php echo number_format($product['price'], 2); ?></strong>
                            <span>Standard Ticket</span>
                        </div>
                        <div class="counter-box">
                            <button type="button" class="counter-btn qty-btn" data-action="minus" data-target="prod-<?php echo $pid; ?>">&#8722;</button>
                            <input type="number" name="tickets[<?php echo $pid; ?>]" id="qty-prod-<?php echo $pid; ?>" value="0" readonly class="counter-value qty-input" data-category="adult">
                            <button type="button" class="counter-btn qty-btn" data-action="plus" data-target="prod-<?php echo $pid; ?>">+</button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>


        <?php if (!empty($addon_tickets)): ?>
        <div class="card">
            <h3 class="section-title">Meal Bundles</h3>
            <div class="pricing-section" style="border:none; padding-top:0;">
                <?php foreach ($addon_tickets as $meal): ?>
                <div class="price-item">
                    <div class="price-label">
                        <strong>AED <?php echo number_format($meal['price'], 2); ?></strong>
                        <span><?php echo htmlspecialchars($meal['name']); ?></span>
                    </div>
                    <div class="counter-box">
                        <button type="button" class="counter-btn qty-btn" data-action="minus" data-target="prod-<?php echo $meal['product_id']; ?>">&#8722;</button>
                        <input type="number" name="tickets[<?php echo $meal['product_id']; ?>]" id="qty-prod-<?php echo $meal['product_id']; ?>" value="0" readonly class="counter-value qty-input" data-category="addon">
                        <button type="button" class="counter-btn qty-btn" data-action="plus" data-target="prod-<?php echo $meal['product_id']; ?>">+</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($sidebar_addons)): ?>
        <h3 class="section-title">Add Ons</h3>
        <div class="upgrades-grid">
            <?php foreach ($sidebar_addons as $addon): ?>
            <div class="upgrade-card">
                <div class="upgrade-content">
                    <div class="upgrade-icons"><i class="fas <?php echo getIconForProduct($addon['name']); ?>"></i></div>
                    <h4><?php echo htmlspecialchars($addon['name']); ?></h4>
                    <p class="addon-desc">Optional Upgrade for Only</p>
                    <span class="upgrade-price">AED <?php echo number_format($addon['price'], 0); ?></span>
                </div>
                <div class="counter-box" style="justify-content: center; margin-top: 15px;">
                    <button type="button" class="counter-btn qty-btn" data-action="minus" data-target="prod-<?php echo $addon['product_id']; ?>">&#8722;</button>
                    <input type="number" name="tickets[<?php echo $addon['product_id']; ?>]" id="qty-prod-<?php echo $addon['product_id']; ?>" value="0" readonly class="counter-value qty-input" data-category="addon">
                    <button type="button" class="counter-btn qty-btn" data-action="plus" data-target="prod-<?php echo $addon['product_id']; ?>">+</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div style="height: 50px;"></div>
    </div>


    <!-- RIGHT COLUMN: Calendar -->
    <div class="right-column">
        <div class="calendar-card">
            <div class="calendar-top-head">
                <div>
                    <h3>Select Visit Date</h3>
                    <p>Choose your preferred day and view real-time remaining slots.</p>
                </div>
                <div class="calendar-live-badge">
                    <i class="fas fa-circle"></i>
                    <span>Live Availability</span>
                </div>
            </div>
            <div id="calendar-container" class="calendar-fade"></div>
            <div class="calendar-legend">
                <div class="legend-item"><span class="legend-dot good"></span><span>Available Slots</span></div>
                <div class="legend-item"><span class="legend-dot low"></span><span>Few Slots Left</span></div>
                <div class="legend-item"><span class="legend-dot full"></span><span>Fully Booked</span></div>
            </div>
            <div class="calendar-note">Availability updates automatically while this page is open.</div>
            <div class="selected-date-summary" id="selected-date-summary">
                <div class="summary-top">
                    <strong>Selected Date</strong>
                    <span class="summary-status none" id="selected-date-status">No Date Yet</span>
                </div>
                <div class="summary-main" id="selected-date-text">Please select a date from the calendar to view availability details.</div>
            </div>
        </div>
    </div>
</div>

<input type="hidden" id="booking_date" name="booking_date">


<!-- FOOTER BAR -->
<div class="ww-footer">
    <div class="ww-footer-inner">
        <div class="ww-footer-left">
            <span class="ww-total-lbl" style="font-size: 0.9rem; color: rgba(255,255,255,0.8);">Total Amount</span>
            <strong class="ww-total-val" id="total-display" style="font-size: 1.5rem;">AED 0.00</strong>
        </div>
        <div class="ww-steps-container">
            <div class="ww-step-circle active">1</div>
            <div class="ww-step-circle">2</div>
            <div class="ww-step-circle">3</div>
        </div>
        <div class="ww-footer-right">
            <a href="kiosk_home.php" class="ww-next-btn" style="background:transparent; border:1px solid rgba(255,255,255,0.5); color:white;">HOME</a>
            <button type="button" id="btn-next-step" class="ww-next-btn" disabled onclick="openDetailsModal()">
                NEXT STEP <i class="fas fa-chevron-right" style="margin-left:8px;"></i>
            </button>
        </div>
    </div>
</div>


<!-- ============ MODAL 1: GUEST DETAILS ============ -->
<div class="kiosk-modal-overlay" id="modal-details">
    <div class="kiosk-modal-box">
        <button class="kiosk-modal-close" onclick="closeModal('modal-details')">&times;</button>
        <h2><i class="fas fa-user-edit"></i> Guest Details</h2>

        <div class="modal-two-col">
            <div class="modal-col-left">
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" id="modal-phone" class="ww-input" placeholder="Enter phone number">
                </div>

                <div class="autofill-loading" id="autofill-loading">
                    <i class="fas fa-spinner fa-spin"></i> Looking up customer...
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>First Name *</label>
                        <input type="text" id="modal-first-name" class="ww-input" placeholder="First Name" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name *</label>
                        <input type="text" id="modal-last-name" class="ww-input" placeholder="Last Name" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Email Address *</label>
                    <input type="email" id="modal-email" class="ww-input" placeholder="email@example.com" required>
                </div>

                <div class="form-group">
                    <label>Company Name (optional)</label>
                    <input type="text" id="modal-company" class="ww-input" placeholder="Company Name">
                </div>

                <div class="form-group">
                    <label>Nationality / Country</label>
                    <select id="modal-country" class="ww-input">
                        <option value="">Select Country</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Are You?</label>
                    <div style="display:flex; gap:20px; margin-top:5px;">
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="radio" name="modal-are-you" value="visitor" checked> Visitor
                        </label>
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="radio" name="modal-are-you" value="residence"> Residence
                        </label>
                    </div>
                </div>

                <div class="agreements-box">
                    <div class="agreement-item">
                        <input type="checkbox" id="modal-agree-terms">
                        <label for="modal-agree-terms">I agree to the <strong class="terms-link" onclick="openTermsSubModal()">Terms &amp; Conditions</strong> and <strong>Privacy Policy</strong>.</label>
                    </div>
                    <div class="agreement-item">
                        <input type="checkbox" id="modal-agree-refund">
                        <label for="modal-agree-refund">I accept the <strong style="color:#d9534f;">Strict No-Refund Policy</strong>.</label>
                    </div>
                </div>

                <button class="kiosk-modal-btn primary" onclick="submitDetails()" style="margin-top:20px;">
                    CONTINUE TO CHECKOUT <i class="fas fa-arrow-right"></i>
                </button>
            </div>

            <div class="modal-col-right">
                <h3><i class="fas fa-receipt"></i> Booking Summary</h3>
                <div class="summary-date-box" id="details-summary-date">
                    <i class="fas fa-calendar-day"></i>
                    <span>No date selected</span>
                </div>
                <div id="details-summary-items"></div>
                <div class="summary-total-box" id="details-summary-total">
                    <div style="font-size:0.75rem; opacity:0.8;">ESTIMATED TOTAL</div>
                    <div class="amount">AED 0.00</div>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- Terms Sub-Modal -->
<div class="terms-sub-modal" id="terms-sub-modal">
    <div class="terms-sub-box">
        <h3><i class="fas fa-file-contract"></i> Terms &amp; Conditions</h3>
        <ol>
            <li>All tickets are non-refundable and non-transferable once purchased. Date changes may be permitted subject to availability and management approval.</li>
            <li>Guests must follow all safety rules and instructions from park staff. Management reserves the right to refuse entry or remove any guest who violates park policies.</li>
            <li>Children under 12 must be accompanied by a paying adult at all times. Height and age restrictions apply to certain attractions for safety reasons.</li>
        </ol>
        <button class="kiosk-modal-btn primary" onclick="closeTermsSubModal()" style="margin-top:15px;">
            I Understand <i class="fas fa-check"></i>
        </button>
    </div>
</div>


<!-- ============ MODAL 2: CHECKOUT ============ -->
<div class="kiosk-modal-overlay" id="modal-checkout">
    <div class="kiosk-modal-box">
        <button class="kiosk-modal-close" onclick="closeModal('modal-checkout')">&times;</button>
        <h2><i class="fas fa-credit-card"></i> Checkout</h2>

        <div class="modal-two-col">
            <div class="modal-col-left">
                <h3 style="color:var(--primary-blue); margin-bottom:15px;">Select Payment Method</h3>

                <label class="payment-option">
                    <input type="radio" name="kiosk-payment" value="card" checked>
                    <div class="radio-dot"></div>
                    <div style="flex:1;">
                        <strong>Credit / Debit Card / Apple Pay</strong>
                        <span style="font-size:0.8rem; color:#666; display:block;">Secure online payment</span>
                    </div>
                    <div style="margin-left:auto; font-size:1.3rem; color:#003B72;">
                        <i class="fab fa-cc-visa"></i> <i class="fab fa-cc-mastercard"></i> <i class="fab fa-apple"></i>
                    </div>
                </label>

                <label class="payment-option">
                    <input type="radio" name="kiosk-payment" value="cash">
                    <div class="radio-dot"></div>
                    <div style="flex:1;">
                        <strong>Pay at Counter (CASH/CARD)</strong>
                        <span style="font-size:0.8rem; color:#666; display:block;">Present your booking ID to the cashier.</span>
                    </div>
                    <div style="margin-left:auto; color:#16a34a; font-size:1.3rem;"><i class="fas fa-money-bill-wave"></i></div>
                </label>

                <label class="payment-option">
                    <input type="radio" name="kiosk-payment" value="qr_points">
                    <div class="radio-dot"></div>
                    <div style="flex:1;">
                        <strong>QR Points / Loyalty Card</strong>
                        <span style="font-size:0.8rem; color:#666; display:block;">Scan your QR code to pay with points.</span>
                    </div>
                    <div style="margin-left:auto; color:#7c3aed; font-size:1.3rem;"><i class="fas fa-qrcode"></i></div>
                </label>

                <!-- QR Inline Status -->
                <div class="qr-inline-status" id="qr-inline-status"></div>

                <div id="qr-scanner-trigger" style="display:none; margin-top:10px;">
                    <button type="button" class="kiosk-modal-btn primary" id="btn-open-scanner" style="background:#7c3aed;">
                        <i class="fas fa-qrcode"></i> Scan QR Code
                    </button>
                </div>

                <button class="kiosk-modal-btn primary" id="btn-pay-now" onclick="submitPayment()" style="margin-top:20px;">
                    PAY NOW <i class="fas fa-lock"></i>
                </button>
            </div>


            <div class="modal-col-right">
                <h3><i class="fas fa-clipboard-list"></i> Order Review</h3>

                <!-- Guest Info -->
                <div class="guest-info-box" id="checkout-guest-info">
                    <p><strong>Guest:</strong> <span id="checkout-guest-name">---</span></p>
                    <p><strong>Email:</strong> <span id="checkout-guest-email">---</span></p>
                    <p><strong>Phone:</strong> <span id="checkout-guest-phone">---</span></p>
                </div>

                <!-- Cart Items -->
                <div id="checkout-summary-items"></div>

                <!-- Discount Code -->
                <div class="discount-box">
                    <h4><i class="fas fa-tag"></i> Discount Code</h4>
                    <div class="discount-row">
                        <input type="text" id="discount-code-input" placeholder="Enter code">
                        <button type="button" onclick="applyDiscount()">APPLY</button>
                    </div>
                    <div id="discount-applied-box" style="display:none;"></div>
                </div>

                <!-- Totals -->
                <div style="margin-top:15px;">
                    <div class="checkout-total-row">
                        <span>Subtotal</span>
                        <strong id="checkout-subtotal">AED 0.00</strong>
                    </div>
                    <div class="checkout-total-row" id="checkout-discount-row" style="display:none; color:#16a34a;">
                        <span>Discount</span>
                        <strong id="checkout-discount-amount">- AED 0.00</strong>
                    </div>
                    <div class="checkout-total-row" style="color:#666;">
                        <span>VAT (5%)</span>
                        <span id="checkout-vat">AED 0.00</span>
                    </div>
                    <div class="checkout-grand-total">
                        <span>GRAND TOTAL</span>
                        <span id="checkout-grand-total">AED 0.00</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- QR Scanner Sub-Overlay -->
<div class="qr-scanner-overlay" id="qr-scanner-overlay">
    <div class="qr-scanner-box">
        <h3 style="color:var(--primary-blue); margin-bottom:15px;"><i class="fas fa-qrcode"></i> Scan QR Code</h3>
        <div id="qr-reader"></div>
        <div class="qr-manual-input">
            <input type="text" id="qr-manual-input" placeholder="Or enter code manually...">
            <button type="button" class="kiosk-modal-btn primary" id="btn-close-scanner" style="width:auto; padding:12px 20px; margin:0;">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>


<!-- ============ MODAL 3: SUCCESS ============ -->
<div class="kiosk-modal-overlay" id="modal-success">
    <div class="kiosk-modal-box" style="text-align:center; max-width:500px;">
        <div class="success-icon"><i class="fas fa-check-circle"></i></div>
        <h2 style="text-align:center;">Booking Confirmed!</h2>
        <p style="color:#666; margin-bottom:15px;">Your booking has been submitted successfully.</p>
        
        <div class="success-booking-id">
            <small style="color:#666;">Booking ID</small><br>
            <strong id="success-booking-id-display">---</strong>
        </div>

        <p id="success-payment-msg" style="color:#16a34a; font-weight:700; margin:15px 0;"></p>

        <button class="kiosk-modal-btn success" onclick="resetKiosk()">
            <i class="fas fa-home"></i> BACK TO HOME
        </button>
    </div>
</div>


<!-- Hidden Form -->
<form id="hidden-process-form" action="kiosk_process.php" method="POST" style="display:none;">
    <input type="hidden" name="is_kiosk" value="1">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <input type="hidden" name="payment_method" id="hidden-payment-method" value="card">
    <input type="hidden" name="first_name" id="hidden-first-name">
    <input type="hidden" name="last_name" id="hidden-last-name">
    <input type="hidden" name="email" id="hidden-email">
    <input type="hidden" name="phone" id="hidden-phone">
    <input type="hidden" name="phone_code" id="hidden-phone-code" value="+971">
    <input type="hidden" name="company_name" id="hidden-company">
    <input type="hidden" name="country" id="hidden-country">
    <input type="hidden" name="are_you" id="hidden-are-you">
    <input type="hidden" name="agree_terms" id="hidden-agree-terms" value="1">
    <input type="hidden" name="agree_refund" id="hidden-agree-refund" value="1">
    <input type="hidden" name="booking_date" id="hidden-booking-date">
    <input type="hidden" name="scanned_qr_code" id="hidden-scanned-qr">
    <div id="hidden-tickets-container"></div>
</form>


<script>
const ticketData = <?php echo json_encode($tickets_json); ?>;
const calendarDateControls = <?php echo json_encode($calendar_date_controls); ?>;
const calendarMonthControls = <?php echo json_encode($calendar_month_controls); ?>;
let calendarBookingCounts = <?php echo json_encode($calendar_booking_counts); ?>;

let phoneInputIti = null;
let select2Initialized = false;
let discountApplied = null;

document.addEventListener('DOMContentLoaded', () => {
    const totalEl = document.getElementById('total-display');
    const nextBtn = document.getElementById('btn-next-step');
    const dateInput = document.getElementById('booking_date');
    const calendarEl = document.getElementById('calendar-container');
    const selectedDateStatusEl = document.getElementById('selected-date-status');
    const selectedDateTextEl = document.getElementById('selected-date-text');

    let currentDate = new Date();
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const todayLocalIso = new Date(today.getTime() - (today.getTimezoneOffset() * 60000)).toISOString().split('T')[0];

    function formatIsoDate(isoDate) {
        const d = new Date(isoDate + 'T00:00:00');
        return d.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    }

    function getDateRule(isoDate) {
        const monthKey = isoDate.substring(0, 7);
        const monthRule = calendarMonthControls[monthKey] || null;
        const dateRule = calendarDateControls[isoDate] || null;
        const booked = parseInt(calendarBookingCounts[isoDate] || 0, 10);
        let isEnabled = true, reason = '', maxBookings = null, notes = '';
        if (monthRule) { isEnabled = parseInt(monthRule.is_enabled, 10) === 1; if (!isEnabled) reason = monthRule.notes || 'This month is unavailable.'; }
        if (dateRule) {
            isEnabled = parseInt(dateRule.is_enabled, 10) === 1;
            maxBookings = (dateRule.max_bookings !== null && dateRule.max_bookings !== '') ? parseInt(dateRule.max_bookings, 10) : null;
            notes = dateRule.notes || '';
            if (!isEnabled) reason = notes || 'This date is disabled.';
        }
        const remainingSlots = (maxBookings !== null) ? Math.max(0, maxBookings - booked) : null;
        if (isEnabled && maxBookings !== null && booked >= maxBookings) { isEnabled = false; reason = notes || 'Fully booked'; }
        return { isEnabled, reason, booked, maxBookings, remainingSlots, notes, isFull: (maxBookings !== null && booked >= maxBookings) };
    }

    function getSlotVisualClass(rule) {
        if (rule.maxBookings === null) return '';
        if (rule.isFull) return '';
        if (!rule.isEnabled) return '';
        if (rule.remainingSlots <= 20) return ' low-slots';
        return ' good-slots';
    }


    function updateSelectedDateSummary() {
        if (!selectedDateStatusEl || !selectedDateTextEl) return;
        if (!dateInput.value) {
            selectedDateStatusEl.className = 'summary-status none';
            selectedDateStatusEl.textContent = 'No Date Yet';
            selectedDateTextEl.innerHTML = 'Please select a date from the calendar to view availability details.';
            return;
        }
        const rule = getDateRule(dateInput.value);
        const prettyDate = formatIsoDate(dateInput.value);
        if (!rule.isEnabled && rule.isFull) { selectedDateStatusEl.className = 'summary-status full'; selectedDateStatusEl.textContent = 'Fully Booked'; }
        else if (rule.maxBookings !== null && rule.remainingSlots !== null && rule.remainingSlots <= 20) { selectedDateStatusEl.className = 'summary-status low'; selectedDateStatusEl.textContent = 'Few Slots Left'; }
        else if (rule.isEnabled) { selectedDateStatusEl.className = 'summary-status good'; selectedDateStatusEl.textContent = 'Available'; }
        else { selectedDateStatusEl.className = 'summary-status full'; selectedDateStatusEl.textContent = 'Unavailable'; }
        if (rule.maxBookings !== null) { selectedDateTextEl.innerHTML = `<strong>${prettyDate}</strong><br>${rule.remainingSlots} slot(s) left out of ${rule.maxBookings}.`; }
        else { selectedDateTextEl.innerHTML = `<strong>${prettyDate}</strong><br>This date is available for booking.`; }
        if (!rule.isEnabled && rule.reason) { selectedDateTextEl.innerHTML = `<strong>${prettyDate}</strong><br>${rule.reason}`; }
    }

    function updateTotals() {
        let total = 0, hasTickets = false;
        document.querySelectorAll('.qty-input').forEach(inp => {
            const rawId = inp.id.replace('qty-', '');
            const qty = parseInt(inp.value) || 0;
            const jsonKey = rawId.replace('-', '_');
            if (qty > 0 && ticketData[jsonKey]) { total += qty * ticketData[jsonKey].price; hasTickets = true; }
            const card = inp.closest('.upgrade-card');
            if (card) card.style.borderColor = qty > 0 ? '#003366' : 'transparent';
        });
        totalEl.textContent = `AED ${total.toFixed(2)}`;
        let selectedDateIsValid = false;
        if (dateInput.value) { const sr = getDateRule(dateInput.value); selectedDateIsValid = sr.isEnabled; }
        if (dateInput.value && selectedDateIsValid && hasTickets) {
            nextBtn.disabled = false; nextBtn.style.opacity = '1'; nextBtn.style.cursor = 'pointer';
        } else {
            nextBtn.disabled = true; nextBtn.style.opacity = '0.5'; nextBtn.style.cursor = 'not-allowed';
        }
        updateSelectedDateSummary();
    }


    function filterProductsByDate() {
    const selectedDate = dateInput.value;

    let activeDayType = null;
    if (selectedDate) {
        const d = new Date(selectedDate + 'T00:00:00');
        const dayOfWeek = d.getDay(); 
        const isWeekend = [0, 5, 6].includes(dayOfWeek); // Fri, Sat, Sun
        activeDayType = isWeekend ? 'weekend' : 'weekday';
    }

    document.querySelectorAll('.product-card').forEach(card => {
        const from = card.dataset.availableFrom || '';
        const until = card.dataset.availableUntil || '';
        const cardDayType = card.dataset.dayType || 'all';

        let isAvailable = true;
        if (selectedDate) {
            if (from && selectedDate < from) isAvailable = false;
            if (until && selectedDate > until) isAvailable = false;
            
            // Hides the WHOLE CARD if weekday is selected on weekend, or vice versa
            if (cardDayType !== 'all' && activeDayType && cardDayType !== activeDayType) {
                isAvailable = false;
            }
        }

        if (!isAvailable) {
            card.classList.add('date-disabled');
            card.querySelectorAll('.qty-input').forEach(inp => { inp.value = 0; });
            const cb = card.querySelector('.bundle-checkbox');
            const counter = card.querySelector('.bundle-counter');
            if (cb) cb.checked = false;
            if (counter) { counter.style.opacity = '0.4'; counter.style.pointerEvents = 'none'; }
        } else { 
            card.classList.remove('date-disabled'); 
            
            // ===== BAGONG DAGDAG: I-filter din ang mga indibidwal na ticket types sa loob ng card =====
            card.querySelectorAll('.ticket-variation').forEach(row => {
                const rowDayType = row.dataset.dayType || 'all';
                if (rowDayType !== 'all' && activeDayType && rowDayType !== activeDayType) {
                    row.style.display = 'none'; // itago ang maling ticket type (e.g. itago ang weekend ticket kung weekday ang napili)
                    const inp = row.querySelector('.qty-input');
                    if (inp) inp.value = 0; // i-reset ang counter nito sa 0
                } else {
                    row.style.display = 'flex'; // ipakita kung tugma ang day type
                }
            });
        }
    });
    updateBundleDependencies();
    updateTotals();
}
    function updateBundleDependencies() {
        const bundleInput = document.querySelector('.bundle-input');
        const extraKidsCard = document.getElementById('extra-kids-card');
        const extraKidsInput = document.querySelector('.extra-kids-input');
        if (!bundleInput) return;
        const bundleQty = parseInt(bundleInput.value) || 0;
        if (extraKidsCard) {
            if (bundleQty > 0) { extraKidsCard.style.opacity = '1'; extraKidsCard.style.pointerEvents = 'auto'; }
            else { extraKidsCard.style.opacity = '0.5'; extraKidsCard.style.pointerEvents = 'none'; if (extraKidsInput) extraKidsInput.value = 0; }
        }
        const priceEl = document.getElementById('bundle-price-display');
        const includesEl = document.getElementById('bundle-includes-text');
        const includesTopEl = document.getElementById('bundle-includes-top');
        const basePrice = priceEl ? parseFloat(priceEl.dataset.basePrice) || 0 : 0;
        const kidPrice = extraKidsInput ? parseFloat(extraKidsInput.dataset.unitPrice) || 0 : 0;
        const extraKids = extraKidsInput ? parseInt(extraKidsInput.value) || 0 : 0;
        const BASE_ADULTS = 2, BASE_KIDS = 2, BASE_MEALS = 4;
        const totalAdults = BASE_ADULTS * Math.max(bundleQty, 0);
        const totalKids = (BASE_KIDS * Math.max(bundleQty, 0)) + extraKids;
        const totalMeals = (BASE_MEALS * Math.max(bundleQty, 0)) + extraKids;
        const linePrice = (basePrice * Math.max(bundleQty, 0)) + (kidPrice * extraKids);
        if (bundleQty > 0) {
            if (includesTopEl) includesTopEl.textContent = `Includes: ${totalAdults} Adults + ${totalKids} Kids + ${totalMeals} Combo Meals`;
            if (includesEl) includesEl.textContent = `With: ${totalAdults} Adults + ${totalKids} Kids + ${totalMeals} Combo Meals`;
            if (priceEl) priceEl.textContent = `AED ${linePrice.toFixed(2)}`;
        } else {
            if (includesTopEl) includesTopEl.textContent = `Includes: ${BASE_ADULTS} Adults + ${BASE_KIDS} Kids + ${BASE_MEALS} Combo Meals`;
            if (includesEl) includesEl.textContent = `With: ${BASE_ADULTS} Adults + ${BASE_KIDS} Kids + ${BASE_MEALS} Combo Meals`;
            if (priceEl) priceEl.textContent = `AED ${basePrice.toFixed(2)}`;
        }
    }


    // Bundle checkbox
    const bundleCheckbox = document.getElementById('bundle-checkbox');
    const bundleCounter = document.querySelector('.bundle-counter');
    const bundleInput = document.querySelector('.bundle-input');
    if (bundleCheckbox) {
        bundleCheckbox.addEventListener('change', function() {
            if (this.checked) { bundleCounter.style.opacity = '1'; bundleCounter.style.pointerEvents = 'auto'; bundleInput.value = 1; }
            else { bundleCounter.style.opacity = '0.4'; bundleCounter.style.pointerEvents = 'none'; bundleInput.value = 0; const eki = document.querySelector('.extra-kids-input'); if (eki) eki.value = 0; }
            updateBundleDependencies(); updateTotals();
        });
    }

    // Qty +/- buttons
document.querySelectorAll('.qty-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        const targetId = this.dataset.target;
        const input = document.getElementById(`qty-${targetId}`);
        if (!input) return;
        
        let val = parseInt(input.value) || 0;
        const isBundle = input.dataset.bundle === '1';
        const requiresBundle = input.dataset.requiresBundle === '1';

        // --- ADULT/CHILD RATIO VALIDATION LOGIC ---
        const card = input.closest('.product-card');
        if (card) {
            const title = card.querySelector('.ticket-info h2')?.innerText.toLowerCase() || '';
            const isRestrictedTicket = title.includes('3 hour') || title.includes('full day');

            if (isRestrictedTicket) {
                // Get category from dataset or label fallback
                let category = input.dataset.category;
                const labelSpan = input.closest('.price-item')?.querySelector('.price-label span')?.innerText.toLowerCase() || '';
                if (labelSpan.includes('kid') || labelSpan.includes('child')) category = 'child';
                if (labelSpan.includes('adult')) category = 'adult';

                let totalAdults = 0;
                let totalChildren = 0;
                
                card.querySelectorAll('.qty-input').forEach(inp => {
                    let cat = inp.dataset.category;
                    const sp = inp.closest('.price-item')?.querySelector('.price-label span')?.innerText.toLowerCase() || '';
                    if (sp.includes('kid') || sp.includes('child')) cat = 'child';
                    if (sp.includes('adult')) cat = 'adult';

                    if (cat === 'adult') totalAdults += parseInt(inp.value) || 0;
                    if (cat === 'child') totalChildren += parseInt(inp.value) || 0;
                });

                if (this.dataset.action === 'plus' && category === 'child') {
                    if (totalAdults === 0) {
                        alert("Please select at least one Adult ticket with or without swim before adding a Child ticket.");
                        return; 
                    }
                }

                if (this.dataset.action === 'minus' && category === 'adult') {
                    if (totalChildren > 0 && totalAdults <= 1) { 
                         alert("You cannot remove the last Adult while Child tickets are selected. Please remove the Child tickets first.");
                         return; 
                    }
                }
            }
        }
        // --- END VALIDATION LOGIC ---

        if (this.dataset.action === 'plus') {
            if (requiresBundle) {
                const bQty = bundleInput ? parseInt(bundleInput.value) || 0 : 0;
                if (bQty === 0) { alert("Please check the Family Package first."); return; }
            }
            input.value = val + 1;
        }
        
        if (this.dataset.action === 'minus') {
            if (isBundle && val === 1) {
                const eki = document.querySelector('.extra-kids-input');
                if (eki && parseInt(eki.value) > 0) { alert("Remove Extra Kids first."); return; }
                if (bundleCheckbox) bundleCheckbox.checked = false;
                if (bundleCounter) { bundleCounter.style.opacity = '0.4'; bundleCounter.style.pointerEvents = 'none'; }
                input.value = 0; 
                updateBundleDependencies(); 
                updateTotals(); 
                return;
            }
            if (val > 0) input.value = val - 1;
        }
        
        updateBundleDependencies(); 
        updateTotals();
    });
});


    // Calendar
    function drawCalendar(month, year) {
        if (!calendarEl) return;
        currentDate = new Date(year, month, 1);
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const months = ["January","February","March","April","May","June","July","August","September","October","November","December"];

        let html = `<div class="calendar-header"><button type="button" id="prev-m" class="cal-nav"><i class="fas fa-chevron-left"></i></button><span>${months[month]} ${year}</span><button type="button" id="next-m" class="cal-nav"><i class="fas fa-chevron-right"></i></button></div><div class="calendar-grid"><div class="day-name">SU</div><div class="day-name">MO</div><div class="day-name">TU</div><div class="day-name">WE</div><div class="day-name">TH</div><div class="day-name">FR</div><div class="day-name">SA</div>`;

        for (let i = 0; i < firstDay; i++) html += `<div class="cal-date empty"></div>`;

        for (let d = 1; d <= daysInMonth; d++) {
            let date = new Date(year, month, d); date.setHours(0,0,0,0);
            let localDate = new Date(date.getTime() - (date.getTimezoneOffset() * 60000)).toISOString().split('T')[0];
            let cls = 'cal-date', sub = '';

            if (date < today) { cls = 'cal-date disabled'; }
            else {
                const rule = getDateRule(localDate);
                const slotClass = getSlotVisualClass(rule);
                if (rule.maxBookings !== null) sub = `<small class="cal-capacity">${rule.remainingSlots} Left</small>`;
                if (rule.isEnabled) {
                    cls = 'cal-date active' + slotClass;
                    if (localDate === todayLocalIso) cls += ' today-ring';
                    if (dateInput.value === localDate) cls += ' selected';
                } else { cls = rule.isFull ? 'cal-date disabled full' : 'cal-date disabled'; }
            }
            html += `<div class="${cls}" data-date="${localDate}"><span class="cal-day-number">${d}</span>${sub}</div>`;
        }
        html += `</div>`;
        calendarEl.classList.remove('calendar-fade'); calendarEl.innerHTML = html;
        void calendarEl.offsetWidth; calendarEl.classList.add('calendar-fade');

        document.getElementById('prev-m')?.addEventListener('click', e => { e.preventDefault(); drawCalendar(month === 0 ? 11 : month - 1, month === 0 ? year - 1 : year); });
        document.getElementById('next-m')?.addEventListener('click', e => { e.preventDefault(); drawCalendar(month === 11 ? 0 : month + 1, month === 11 ? year + 1 : year); });

        document.querySelectorAll('.cal-date.active').forEach(cell => {
            cell.addEventListener('click', function() {
                const chosenDate = this.dataset.date;
                const rule = getDateRule(chosenDate);
                if (!rule.isEnabled) return;
                document.querySelectorAll('.cal-date.selected').forEach(c => c.classList.remove('selected'));
                this.classList.add('selected');
                dateInput.value = chosenDate;
                filterProductsByDate(); updateTotals();
            });
        });
        updateTotals();
    }

    drawCalendar(currentDate.getMonth(), currentDate.getFullYear());
    updateTotals();

    // Auto-refresh booking counts every 10s
    setInterval(async () => {
        try {
            const res = await fetch('calendar_counts.php', { cache: 'no-store' });
            const data = await res.json();
            if (data.success && data.counts) { calendarBookingCounts = data.counts; drawCalendar(currentDate.getMonth(), currentDate.getFullYear()); }
        } catch(e) {}
    }, 10000);
});


// ===== MODAL FUNCTIONS =====
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

function openTermsSubModal() { document.getElementById('terms-sub-modal').classList.add('active'); }
function closeTermsSubModal() { document.getElementById('terms-sub-modal').classList.remove('active'); }

// ===== DETAILS MODAL PLUGINS =====
function initDetailsModalPlugins() {
    // intl-tel-input
    const phoneEl = document.getElementById('modal-phone');
    if (phoneEl && !phoneInputIti) {
        phoneInputIti = window.intlTelInput(phoneEl, {
            initialCountry: 'ae',
            preferredCountries: ['ae', 'ph', 'in', 'pk', 'bd', 'eg', 'jo', 'sa', 'gb', 'us'],
            separateDialCode: true,
            utilsScript: 'https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/utils.js'
        });
    }

    // Country dropdown with flag emojis + Select2
    if (!select2Initialized) {
        const countrySelect = document.getElementById('modal-country');
        const countries = [
            {code:'AE',name:'United Arab Emirates',flag:'\uD83C\uDDE6\uD83C\uDDEA'},
            {code:'PH',name:'Philippines',flag:'\uD83C\uDDF5\uD83C\uDDED'},
            {code:'IN',name:'India',flag:'\uD83C\uDDEE\uD83C\uDDF3'},
            {code:'PK',name:'Pakistan',flag:'\uD83C\uDDF5\uD83C\uDDF0'},
            {code:'BD',name:'Bangladesh',flag:'\uD83C\uDDE7\uD83C\uDDE9'},
            {code:'EG',name:'Egypt',flag:'\uD83C\uDDEA\uD83C\uDDEC'},
            {code:'JO',name:'Jordan',flag:'\uD83C\uDDEF\uD83C\uDDF4'},
            {code:'SA',name:'Saudi Arabia',flag:'\uD83C\uDDF8\uD83C\uDDE6'},
            {code:'GB',name:'United Kingdom',flag:'\uD83C\uDDEC\uD83C\uDDE7'},
            {code:'US',name:'United States',flag:'\uD83C\uDDFA\uD83C\uDDF8'},
            {code:'CN',name:'China',flag:'\uD83C\uDDE8\uD83C\uDDF3'},
            {code:'RU',name:'Russia',flag:'\uD83C\uDDF7\uD83C\uDDFA'},
            {code:'DE',name:'Germany',flag:'\uD83C\uDDE9\uD83C\uDDEA'},
            {code:'FR',name:'France',flag:'\uD83C\uDDEB\uD83C\uDDF7'},
            {code:'IT',name:'Italy',flag:'\uD83C\uDDEE\uD83C\uDDF9'},
            {code:'AU',name:'Australia',flag:'\uD83C\uDDE6\uD83C\uDDFA'},
            {code:'CA',name:'Canada',flag:'\uD83C\uDDE8\uD83C\uDDE6'},
            {code:'NG',name:'Nigeria',flag:'\uD83C\uDDF3\uD83C\uDDEC'},
            {code:'KE',name:'Kenya',flag:'\uD83C\uDDF0\uD83C\uDDEA'},
            {code:'ZA',name:'South Africa',flag:'\uD83C\uDDFF\uD83C\uDDE6'},
            {code:'OTHER',name:'Other',flag:'\uD83C\uDF10'}
        ];
        countrySelect.innerHTML = '<option value="">Select Country</option>';
        countries.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.name;
            opt.textContent = c.flag + ' ' + c.name;
            countrySelect.appendChild(opt);
        });
        $('#modal-country').select2({
            placeholder: 'Select Country',
            allowClear: true,
            dropdownParent: $('#modal-details .kiosk-modal-box')
        });
        select2Initialized = true;
    }

    // Phone autofill
    initPhoneAutofill();
}


// ===== PHONE AUTOFILL (same logic as details.php) =====
function initPhoneAutofill() {
    const phoneEl = document.getElementById('modal-phone');
    if (!phoneEl || phoneEl.dataset.autofillBound === '1') return;
    phoneEl.dataset.autofillBound = '1';

    const loadingEl = document.getElementById('autofill-loading');
    const firstNameEl = document.getElementById('modal-first-name');
    const lastNameEl = document.getElementById('modal-last-name');
    const emailEl = document.getElementById('modal-email');
    const companyEl = document.getElementById('modal-company');
    const countryEl = document.getElementById('modal-country');
    let typingTimer;

    phoneEl.addEventListener('input', function() {
        clearTimeout(typingTimer);

        // Use intl-tel-input to get the full international number (e.g. +971501234567)
        let fullInternationalNumber = phoneInputIti ? phoneInputIti.getNumber() : this.value;
        let rawDigits = fullInternationalNumber.replace(/[^0-9]/g, '');

        if (rawDigits.length >= 9) {
            loadingEl.classList.add('active');
            loadingEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching existing customer...';

            typingTimer = setTimeout(function() {
                // GET request with full international number — same as details.php
                fetch('api_check_customer.php?phone=' + encodeURIComponent(fullInternationalNumber))
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.name) {
                            loadingEl.classList.remove('active');

                            // Split name into first + last
                            let fullName = data.name.trim();
                            let nameParts = fullName.split(' ');
                            if (nameParts.length > 1) {
                                let lastName = nameParts.pop();
                                firstNameEl.value = nameParts.join(' ');
                                lastNameEl.value = lastName;
                            } else {
                                firstNameEl.value = fullName;
                            }

                            // Email (skip pos placeholder)
                            if (data.email && data.email !== 'pos@ajmanwaterpark.com') {
                                emailEl.value = data.email;
                            }

                            // Company
                            if (data.company) {
                                companyEl.value = data.company;
                            }

                            // Country — match by value in dropdown
                            if (data.country && data.country.trim() !== '') {
                                let dbCountry = data.country.trim().toLowerCase();
                                let options = countryEl.options;
                                for (let i = 0; i < options.length; i++) {
                                    if (options[i].value.toLowerCase() === dbCountry) {
                                        countryEl.selectedIndex = i;
                                        $('#modal-country').trigger('change');
                                        break;
                                    }
                                }
                            }

                            // GREEN FLASH EFFECT
                            const flashColor = '#d4edda';
                            firstNameEl.style.backgroundColor = flashColor;
                            lastNameEl.style.backgroundColor = flashColor;
                            if (emailEl.value) emailEl.style.backgroundColor = flashColor;
                            if (companyEl.value) companyEl.style.backgroundColor = flashColor;
                            if (countryEl.value) $('.select2-selection').css('background-color', flashColor);

                            setTimeout(() => {
                                firstNameEl.style.backgroundColor = '';
                                lastNameEl.style.backgroundColor = '';
                                emailEl.style.backgroundColor = '';
                                companyEl.style.backgroundColor = '';
                                $('.select2-selection').css('background-color', '');
                            }, 1000);

                        } else {
                            loadingEl.innerHTML = '<i class="fas fa-info-circle"></i> No exact record found. New guest.';
                            setTimeout(() => { loadingEl.classList.remove('active'); }, 2500);
                        }
                    })
                    .catch(error => {
                        loadingEl.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Error searching.';
                        console.error("Auto-fill error:", error);
                        setTimeout(() => { loadingEl.classList.remove('active'); }, 2500);
                    });
            }, 1000);
        } else {
            loadingEl.classList.remove('active');
        }
    });
}


// ===== OPEN DETAILS MODAL =====
function openDetailsModal() {
    const dateInput = document.getElementById('booking_date');
    if (!dateInput.value) { alert('Please select a booking date first.'); return; }
    let hasTickets = false;
    document.querySelectorAll('.qty-input').forEach(inp => { if (parseInt(inp.value) > 0) hasTickets = true; });
    if (!hasTickets) { alert('Please select at least one ticket.'); return; }

    // Build summary
    buildDetailsSummary();
    openModal('modal-details');
    setTimeout(initDetailsModalPlugins, 100);
}

// ===== BUILD DETAILS SUMMARY =====
function buildDetailsSummary() {
    const dateInput = document.getElementById('booking_date');
    const summaryDateEl = document.getElementById('details-summary-date');
    const summaryItemsEl = document.getElementById('details-summary-items');
    const summaryTotalEl = document.getElementById('details-summary-total');

    // Date
    if (dateInput.value) {
        const d = new Date(dateInput.value + 'T00:00:00');
        const formatted = d.toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
        summaryDateEl.innerHTML = `<i class="fas fa-calendar-day"></i><span>${formatted}</span>`;
    }

    // Items
    let html = '';
    let total = 0;
    document.querySelectorAll('.qty-input').forEach(inp => {
        const rawId = inp.id.replace('qty-', '');
        const qty = parseInt(inp.value) || 0;
        const jsonKey = rawId.replace('-', '_');
        if (qty > 0 && ticketData[jsonKey]) {
            const price = ticketData[jsonKey].price;
            const subtotal = qty * price;
            total += subtotal;
            let label = rawId;
            const card = inp.closest('.card, .upgrade-card, .extra-kids-card, .bundle-card');
            if (card) {
                const h2 = card.querySelector('h2');
                const h4 = card.querySelector('h4');
                const span = inp.closest('.price-item')?.querySelector('.price-label span');
                if (span) label = span.textContent;
                else if (h4) label = h4.textContent;
                else if (h2) label = h2.textContent;
            }
            const iconClass = getSummaryIconByLabel(label);
            html += `<div class="summary-item"><i class="fas ${iconClass}"></i><span>${label} x${qty}</span><strong style="margin-left:auto;">AED ${subtotal.toFixed(2)}</strong></div>`;
        }
    });
    summaryItemsEl.innerHTML = html || '<p style="color:#999; font-size:0.85rem;">No items selected</p>';
    summaryTotalEl.innerHTML = `<div style="font-size:0.75rem; opacity:0.8;">ESTIMATED TOTAL</div><div class="amount">AED ${total.toFixed(2)}</div>`;
}

function getSummaryIconByLabel(label) {
    const n = label.toLowerCase();
    if (n.includes('adult') || n.includes('standard')) return 'fa-user';
    if (n.includes('child') || n.includes('kid')) return 'fa-child';
    if (n.includes('infant')) return 'fa-baby';
    if (n.includes('parking')) return 'fa-square-parking';
    if (n.includes('locker')) return 'fa-lock';
    if (n.includes('meal') || n.includes('combo')) return 'fa-utensils';
    if (n.includes('photo')) return 'fa-camera';
    if (n.includes('zipline')) return 'fa-wind';
    if (n.includes('bridge')) return 'fa-bridge-water';
    if (n.includes('family') || n.includes('bundle')) return 'fa-gift';
    return 'fa-ticket';
}


// ===== SUBMIT DETAILS =====
function submitDetails() {
    const firstName = document.getElementById('modal-first-name').value.trim();
    const lastName = document.getElementById('modal-last-name').value.trim();
    const email = document.getElementById('modal-email').value.trim();
    const phone = document.getElementById('modal-phone').value.trim();
    const agreeTerms = document.getElementById('modal-agree-terms').checked;
    const agreeRefund = document.getElementById('modal-agree-refund').checked;

    if (!firstName || !lastName) { alert('Please enter your full name.'); return; }
    if (!email || !email.includes('@')) { alert('Please enter a valid email address.'); return; }
    if (!phone) { alert('Please enter a phone number.'); return; }
    if (!agreeTerms || !agreeRefund) { alert('Please agree to the Terms & Conditions and Refund Policy.'); return; }

    // --- FINAL ADULT/CHILD RATIO VALIDATION ---
    let ratioValid = true;
    document.querySelectorAll('.product-card').forEach(card => {
        const title = card.querySelector('.ticket-info h2')?.innerText.toLowerCase() || '';
        const isRestrictedTicket = title.includes('3 hour') || title.includes('full day');
        
        if (isRestrictedTicket) {
            let totalAdults = 0;
            let totalChildren = 0;
            card.querySelectorAll('.qty-input').forEach(inp => {
                let cat = inp.dataset.category;
                const sp = inp.closest('.price-item')?.querySelector('.price-label span')?.innerText.toLowerCase() || '';
                if (sp.includes('kid') || sp.includes('child')) cat = 'child';
                if (sp.includes('adult')) cat = 'adult';

                if (cat === 'adult') totalAdults += parseInt(inp.value) || 0;
                if (cat === 'child') totalChildren += parseInt(inp.value) || 0;
            });
            if (totalChildren > 0 && totalAdults === 0) {
                ratioValid = false;
            }
        }
    });

    if (!ratioValid) {
        alert("Error: You have selected Child/Kids tickets without an Adult ticket. Please add an Adult ticket or remove the Child/Kids tickets before proceeding.");
        closeModal('modal-details'); 
        return;
    }
    // --- END FINAL VALIDATION ---

    // Build checkout summary
    buildCheckoutSummary();
    closeModal('modal-details');
    openModal('modal-checkout');
}

// ===== BUILD CHECKOUT SUMMARY =====
function buildCheckoutSummary() {
    // Guest info
    const guestName = document.getElementById('modal-first-name').value.trim() + ' ' + document.getElementById('modal-last-name').value.trim();
    const guestEmail = document.getElementById('modal-email').value.trim();
    const guestPhone = document.getElementById('modal-phone').value.trim();
    document.getElementById('checkout-guest-name').textContent = guestName;
    document.getElementById('checkout-guest-email').textContent = guestEmail;
    document.getElementById('checkout-guest-phone').textContent = guestPhone;

    // Cart items
    const container = document.getElementById('checkout-summary-items');
    container.innerHTML = '';
    let subtotal = getCartSubtotal();

    document.querySelectorAll('.qty-input').forEach(inp => {
        const rawId = inp.id.replace('qty-', '');
        const qty = parseInt(inp.value) || 0;
        const jsonKey = rawId.replace('-', '_');
        if (qty > 0 && ticketData[jsonKey]) {
            const price = ticketData[jsonKey].price;
            const itemTotal = qty * price;
            let label = rawId;
            const card = inp.closest('.card, .upgrade-card, .extra-kids-card, .bundle-card');
            if (card) {
                const h2 = card.querySelector('h2');
                const h4 = card.querySelector('h4');
                const span = inp.closest('.price-item')?.querySelector('.price-label span');
                if (span) label = span.textContent;
                else if (h4) label = h4.textContent;
                else if (h2) label = h2.textContent;
            }
            container.innerHTML += `<div class="checkout-item"><span>${label} x${qty}</span><strong>AED ${itemTotal.toFixed(2)}</strong></div>`;
        }
    });

    recalculateCheckoutTotals(subtotal);
}

function getCartSubtotal() {
    let total = 0;
    document.querySelectorAll('.qty-input').forEach(inp => {
        const rawId = inp.id.replace('qty-', '');
        const qty = parseInt(inp.value) || 0;
        const jsonKey = rawId.replace('-', '_');
        if (qty > 0 && ticketData[jsonKey]) { total += qty * ticketData[jsonKey].price; }
    });
    return total;
}

function recalculateCheckoutTotals(subtotal) {
    let discountAmount = 0;
    if (discountApplied) {
        if (discountApplied.type === 'percentage') { discountAmount = subtotal * (discountApplied.value / 100); }
        else { discountAmount = discountApplied.value; }
        discountAmount = Math.min(discountAmount, subtotal);
        document.getElementById('checkout-discount-row').style.display = 'flex';
        document.getElementById('checkout-discount-amount').textContent = `- AED ${discountAmount.toFixed(2)}`;
    } else {
        document.getElementById('checkout-discount-row').style.display = 'none';
    }
    const afterDiscount = subtotal - discountAmount;
    const vat = afterDiscount * 0.05;
    const grandTotal = afterDiscount + vat;
    document.getElementById('checkout-subtotal').textContent = `AED ${subtotal.toFixed(2)}`;
    document.getElementById('checkout-vat').textContent = `AED ${vat.toFixed(2)}`;
    document.getElementById('checkout-grand-total').textContent = `AED ${grandTotal.toFixed(2)}`;
}


// ===== DISCOUNT =====
function applyDiscount() {
    const code = document.getElementById('discount-code-input').value.trim();
    if (!code) { alert('Please enter a discount code.'); return; }

    fetch('check_discount.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'code=' + encodeURIComponent(code)
    })
    .then(r => r.json())
    .then(data => {
        if (data.valid) {
            discountApplied = { code: code, type: data.type, value: parseFloat(data.value) };
            const displayText = data.type === 'percentage' ? `${data.value}% OFF` : `AED ${parseFloat(data.value).toFixed(2)} OFF`;
            document.getElementById('discount-applied-box').style.display = 'block';
            document.getElementById('discount-applied-box').innerHTML = `<div class="discount-applied"><span><i class="fas fa-tag" style="color:#16a34a;"></i> <strong>${code}</strong> - ${displayText}</span><span class="remove-discount" onclick="removeDiscount()"><i class="fas fa-times"></i></span></div>`;
            document.getElementById('discount-code-input').disabled = true;
            recalculateCheckoutTotals(getCartSubtotal());
        } else {
            alert(data.message || 'Invalid discount code.');
        }
    })
    .catch(() => { alert('Error checking discount code. Please try again.'); });
}

function removeDiscount() {
    discountApplied = null;
    document.getElementById('discount-applied-box').style.display = 'none';
    document.getElementById('discount-applied-box').innerHTML = '';
    document.getElementById('discount-code-input').disabled = false;
    document.getElementById('discount-code-input').value = '';
    recalculateCheckoutTotals(getCartSubtotal());
}


// ===== QR SCANNER =====
let html5QrScanner = null;

function startQrScanner() {
    document.getElementById('qr-scanner-overlay').classList.add('active');
    if (!html5QrScanner) {
        html5QrScanner = new Html5Qrcode("qr-reader");
    }
    html5QrScanner.start(
        { facingMode: "environment" },
        { fps: 10, qrbox: { width: 250, height: 250 } },
        onQrScanSuccess,
        () => {}
    ).catch(err => { console.log('QR Scanner error:', err); });
}

function stopQrScanner() {
    if (html5QrScanner) {
        html5QrScanner.stop().then(() => {}).catch(() => {});
    }
    document.getElementById('qr-scanner-overlay').classList.remove('active');
}

function onQrScanSuccess(decodedText) {
    stopQrScanner();
    document.getElementById('hidden-scanned-qr').value = decodedText;
    // Check balance
    const statusEl = document.getElementById('qr-inline-status');
    statusEl.classList.add('active');
    statusEl.className = 'qr-inline-status active';
    statusEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking QR balance...';

    fetch('check_qr_balance.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'qr_code=' + encodeURIComponent(decodedText)
    })
    .then(r => r.json())
    .then(data => {
        if (data.valid && data.balance !== undefined) {
            const balance = parseFloat(data.balance);
            const grandTotal = getCartSubtotal();
            if (balance >= grandTotal) {
                statusEl.className = 'qr-inline-status active success';
                statusEl.innerHTML = `<i class="fas fa-check-circle"></i> QR Valid! Balance: AED ${balance.toFixed(2)} - Sufficient for this order.`;
            } else {
                statusEl.className = 'qr-inline-status active error';
                statusEl.innerHTML = `<i class="fas fa-exclamation-circle"></i> Insufficient balance (AED ${balance.toFixed(2)}). Need AED ${grandTotal.toFixed(2)}.`;
            }
        } else {
            statusEl.className = 'qr-inline-status active error';
            statusEl.innerHTML = `<i class="fas fa-times-circle"></i> ${data.message || 'Invalid QR code.'}`;
        }
    })
    .catch(() => {
        statusEl.className = 'qr-inline-status active error';
        statusEl.innerHTML = '<i class="fas fa-times-circle"></i> Error checking QR balance.';
    });
}


// ===== QR DOMContentLoaded Setup =====
document.addEventListener('DOMContentLoaded', function() {
    // Open scanner button
    const btnOpenScanner = document.getElementById('btn-open-scanner');
    if (btnOpenScanner) {
        btnOpenScanner.addEventListener('click', startQrScanner);
    }

    // Close scanner button
    const btnCloseScanner = document.getElementById('btn-close-scanner');
    if (btnCloseScanner) {
        btnCloseScanner.addEventListener('click', stopQrScanner);
    }

    // Manual QR input with debounce
    const manualInput = document.getElementById('qr-manual-input');
    let manualDebounce = null;
    if (manualInput) {
        manualInput.addEventListener('input', function() {
            clearTimeout(manualDebounce);
            const val = this.value.trim();
            if (val.length >= 6) {
                manualDebounce = setTimeout(() => { onQrScanSuccess(val); }, 800);
            }
        });
    }

    // Payment method radio change handler
    document.querySelectorAll('input[name="kiosk-payment"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const qrTrigger = document.getElementById('qr-scanner-trigger');
            const qrStatus = document.getElementById('qr-inline-status');
            if (this.value === 'qr_points') {
                qrTrigger.style.display = 'block';
            } else {
                qrTrigger.style.display = 'none';
                qrStatus.classList.remove('active');
            }
            // Highlight selected payment option
            document.querySelectorAll('.payment-option').forEach(opt => opt.classList.remove('selected-method'));
            this.closest('.payment-option').classList.add('selected-method');
        });
    });
});


// ===== SUBMIT PAYMENT =====
function submitPayment() {
    const payBtn = document.getElementById('btn-pay-now');
    payBtn.disabled = true;
    payBtn.innerHTML = 'PROCESSING... <i class="fas fa-spinner fa-spin"></i>';
    payBtn.style.opacity = '0.6';

    const paymentMethod = document.querySelector('input[name="kiosk-payment"]:checked').value;

    // Populate hidden form
    document.getElementById('hidden-first-name').value = document.getElementById('modal-first-name').value.trim();
    document.getElementById('hidden-last-name').value = document.getElementById('modal-last-name').value.trim();
    document.getElementById('hidden-email').value = document.getElementById('modal-email').value.trim();
    document.getElementById('hidden-phone').value = document.getElementById('modal-phone').value.trim();
    document.getElementById('hidden-company').value = document.getElementById('modal-company').value.trim();
    document.getElementById('hidden-country').value = document.getElementById('modal-country').value;
    document.getElementById('hidden-payment-method').value = paymentMethod;
    document.getElementById('hidden-booking-date').value = document.getElementById('booking_date').value;

    // Phone code from intl-tel-input
    if (phoneInputIti) {
        const countryData = phoneInputIti.getSelectedCountryData();
        document.getElementById('hidden-phone-code').value = '+' + countryData.dialCode;
    }

    const areYouRadio = document.querySelector('input[name="modal-are-you"]:checked');
    document.getElementById('hidden-are-you').value = areYouRadio ? areYouRadio.value : 'visitor';

    // Inject ticket quantities
    const ticketsContainer = document.getElementById('hidden-tickets-container');
    ticketsContainer.innerHTML = '';
    document.querySelectorAll('.qty-input').forEach(inp => {
        const qty = parseInt(inp.value) || 0;
        if (qty > 0) {
            const name = inp.getAttribute('name');
            if (name) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = name;
                hidden.value = qty;
                ticketsContainer.appendChild(hidden);
            }
        }
    });

    // For CASH: use AJAX then show success modal
    if (paymentMethod === 'cash') {
        const formData = new FormData(document.getElementById('hidden-process-form'));
        fetch('kiosk_process.php', { method: 'POST', body: formData })
            .then(response => {
                if (response.redirected) {
                    const url = new URL(response.url);
                    const bookingId = url.searchParams.get('booking_id');
                    showSuccessModal(bookingId, 'cash');
                } else {
                    return response.text().then(text => {
                        // Try to extract booking ID from response
                        const match = text.match(/booking_id=(\d+)/);
                        const bid = match ? match[1] : '';
                        showSuccessModal(bid, 'cash');
                    });
                }
            })
            .catch(err => {
                // Fallback: submit form normally
                document.getElementById('hidden-process-form').submit();
            });
    } else {
        // For card/qr_points: submit form normally (will redirect to payment gateway)
        document.getElementById('hidden-process-form').submit();
    }
}


// ===== SUCCESS MODAL =====
function showSuccessModal(bookingId, method) {
    closeModal('modal-checkout');
    document.getElementById('success-booking-id-display').textContent = bookingId ? '#' + bookingId.toString().padStart(6, '0') : 'Processing...';
    if (method === 'cash') {
        document.getElementById('success-payment-msg').textContent = 'Please proceed to the cashier to complete payment.';
    } else if (method === 'qr_points') {
        document.getElementById('success-payment-msg').textContent = 'Points deducted successfully!';
    } else {
        document.getElementById('success-payment-msg').textContent = 'Payment completed successfully!';
    }
    openModal('modal-success');
}

// ===== RESET KIOSK =====
function resetKiosk() {
    window.location.href = 'kiosk_home.php';
}

// ===== AUTO-SHOW SUCCESS ON PAGE LOAD =====
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);

    // Success redirect from payment gateway
    if (urlParams.get('success') === '1') {
        const bookingId = urlParams.get('booking_id') || '---';
        const method = urlParams.get('method') || 'card';
        document.getElementById('success-booking-id-display').textContent = bookingId ? '#' + bookingId.toString().padStart(6, '0') : '---';
        if (method === 'cash') {
            document.getElementById('success-payment-msg').textContent = 'Please proceed to the cashier to complete payment.';
        } else {
            document.getElementById('success-payment-msg').textContent = 'Payment completed successfully!';
        }
        openModal('modal-success');
        // Clean URL so refresh won't re-show
        window.history.replaceState({}, document.title, 'kiosk_book.php');
    }

    // Cancelled payment alert
    if (urlParams.get('error') === 'payment_cancelled') {
        alert('Payment was cancelled. Please try again.');
        window.history.replaceState({}, document.title, 'kiosk_book.php');
    }
});
</script>

</body>
</html>