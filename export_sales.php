<?php
session_start();
include_once 'db_connect.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    die("Unauthorized access.");
}

$type = $_GET['type'] ?? 'daily';
$action = $_GET['action'] ?? 'print';

// === DATE RANGE GENERATOR LOGIC ===
$dateRangeStr = "";
if ($type === 'daily') {
    $report_date = $_GET['report_date'] ?? date('Y-m-d');
    $start = $report_date . ' 00:00:00';
    $end = $report_date . ' 23:59:59';
    $dateRangeStr = "Date: " . date('F d, Y', strtotime($report_date));
} else {
    $report_month = $_GET['report_month'] ?? date('Y-m');
    $start = $report_month . '-01 00:00:00';
    $end = date("Y-m-t", strtotime($start)) . ' 23:59:59';
    $dateRangeStr = "Month: " . date('F Y', strtotime($start));
}

// CAPTURE FILTERS FROM DASHBOARD
$filter_payment = $_GET['filter_payment'] ?? 'all';
$filter_cashier = $_GET['filter_cashier'] ?? 'all';
$allowed_cats = $_GET['filter_categories'] ?? ['tickets', 'qr_wallet', 'annual_passes', 'addons'];

$sqlPaymentFilter = "";
if ($filter_payment === 'cash') {
    $sqlPaymentFilter = " AND LOWER(b.payment_method) = 'cash'";
} elseif ($filter_payment === 'card') {
    $sqlPaymentFilter = " AND LOWER(b.payment_method) IN ('card', 'credit card', 'credit_card', 'stripe')";
} elseif ($filter_payment === 'qr_points') {
    $sqlPaymentFilter = " AND LOWER(b.payment_method) IN ('qr_points', 'qr_wallet', 'qr_points')";
} elseif ($filter_payment === 'online_system') {
    $sqlPaymentFilter = " AND (b.cashier_name IS NULL OR b.cashier_name = '')";
}


// 1. Check if Add-on Logs are Enabled
$addonLogsEnabled = false;
try {
    $pdo->query("SELECT 1 FROM addon_purchase_logs LIMIT 1");
    $addonLogsEnabled = true;
} catch (Exception $e) {
    $addonLogsEnabled = false;
}

// 2. Fetch Main Bookings Data
$sqlSales = "
    SELECT b.*,
    (SELECT COUNT(*) FROM ticket_instances t WHERE t.booking_id = b.booking_id AND t.is_used = 1) as used_tix_count,
    (SELECT COUNT(*) FROM pass_visits pv WHERE pv.booking_id = b.booking_id) as pass_visit_count
    FROM bookings b
    WHERE b.payment_status = 'paid' AND b.created_at BETWEEN ? AND ? $sqlPaymentFilter
";

$queryParams = [$start, $end];

if ($filter_cashier !== 'all') {
    if ($filter_cashier === 'online_system') {
        $sqlSales .= " AND (b.cashier_name IS NULL OR b.cashier_name = '') ";
    } else {
        $sqlSales .= " AND b.cashier_name = ? ";
        $queryParams[] = $filter_cashier;
    }
}

$sqlSales .= " ORDER BY b.created_at ASC";

$stmt = $pdo->prepare($sqlSales);
$stmt->execute($queryParams);
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);


// AGGREGATE VARIABLES
$totalRevenue = 0;
$totalTicketsItemsQty = 0;

$cash_count = 0;   $cash_total = 0;
$card_count = 0;   $card_total = 0;
// NEW: Split Card into Online vs Manual
$card_online_count = 0;  $card_online_total = 0;
$card_manual_count = 0;  $card_manual_total = 0;

$qr_count = 0;     $qr_total = 0;
$online_count = 0; $online_total = 0;
$other_count = 0;  $other_total = 0;

// CATEGORIZED BREAKDOWN MAPS
$tickets_breakdown = [];
$annual_passes_breakdown = [];
$qr_breakdown = [
    'New QR Wallet Card' => ['qty' => 0, 'price' => 0.00, 'total' => 0.00],
    'QR Wallet Top-up'   => ['qty' => 0, 'price' => 0.00, 'total' => 0.00]
];
$addons_breakdown = [];


// Dynamic Initialization of Ticket Types and Annual Passes from Database
$stmtInitTix = $pdo->query("
    SELECT 
        COALESCE(CONCAT(p.name, ' - ', tt.category), tt.category) AS item_name,
        COALESCE(tt.price, 0) AS base_price,
        tt.product_id
    FROM ticket_types tt
    LEFT JOIN products p ON tt.product_id = p.product_id
");
while ($rowTix = $stmtInitTix->fetch(PDO::FETCH_ASSOC)) {
    $name = $rowTix['item_name'];
    $price = (float)$rowTix['base_price'];

    // --- BAGONG LOGIC PARA SA 3 HOURS PASS (WEEKDAYS AT WEEKENDS) ---
    if (stripos($name, '3 hours') !== false && stripos($name, 'pass') !== false && stripos($name, 'adult') !== false) {
        // Alamin kung ito ay Weekend o Weekday
        $dayType = (stripos($name, 'weekend') !== false) ? 'Weekends' : 'Weekdays';
        
        // TANDAAN: Palitan ang "50" ng totoong presyo ng Adult WITH SWIM ticket.
        if ($price >= 50) { 
            $name = "3 Hours $dayType Pass Ticket - Adult with swim";
        } else {
            $name = "3 Hours $dayType Pass Ticket - Adult without swim";
        }
    }
    // -----------------------------------------------------------------

    if (!empty($name)) {
        if (strpos(strtolower($name), 'annual') !== false || strpos(strtoupper($rowTix['product_id'] ?? ''), 'AP') === 0) {
            $annual_passes_breakdown[$name] = ['qty' => 0, 'price' => $price, 'total' => 0.00];
        } else {
            $tickets_breakdown[$name] = ['qty' => 0, 'price' => $price, 'total' => 0.00];
        }
    }
}

// Dynamic Initialization of Standalone Add-ons
$stmtInitAddons = $pdo->query("
    SELECT name, COALESCE(price, 0) AS base_price, product_id, category_id FROM products 
    WHERE product_id NOT IN (SELECT DISTINCT product_id FROM ticket_types WHERE product_id IS NOT NULL)
      AND product_id NOT LIKE 'QRN_%' AND product_id NOT LIKE 'QRR_%'
");
while ($rowAddon = $stmtInitAddons->fetch(PDO::FETCH_ASSOC)) {
    $name = $rowAddon['name'];
    if (!empty($name)) {
        if (strpos(strtolower($name), 'annual') !== false || strpos(strtoupper($rowAddon['product_id'] ?? ''), 'AP') === 0) {
            $annual_passes_breakdown[$name] = ['qty' => 0, 'price' => (float)$rowAddon['base_price'], 'total' => 0.00];
        } elseif ((int)($rowAddon['category_id'] ?? 0) === 1) {
            // Category 1 = General Admission (packages/tickets like FAM-PKG)
            $tickets_breakdown[$name] = ['qty' => 0, 'price' => (float)$rowAddon['base_price'], 'total' => 0.00];
        } else {
            $addons_breakdown[$name] = ['qty' => 0, 'price' => (float)$rowAddon['base_price'], 'total' => 0.00];
        }
    }
}


if (!empty($sales)) {
    $booking_ids = array_column($sales, 'booking_id');
    $inQuery = implode(',', array_fill(0, count($booking_ids), '?'));

    // Fetch Item Names, Categories, and Prices
    $sqlItems = "
        SELECT 
            bi.booking_id, 
            bi.quantity, 
            bi.product_id,
            CASE 
                WHEN bi.product_id LIKE 'QRN_%' THEN 'New QR Wallet Card'
                WHEN bi.product_id LIKE 'QRR_%' THEN 'QR Wallet Top-up'
                ELSE COALESCE(
                    CONCAT(p_pkg.name, ' - ', tt.category), 
                    tt.category, 
                    p.name, 
                    bi.product_id
                ) 
            END AS item_name,
            p.category_id,
            COALESCE(tt.price, p.price, 0) AS transaction_price
        FROM booking_items bi
        LEFT JOIN ticket_types tt ON bi.product_id = CONCAT('type_', tt.type_id)
        LEFT JOIN products p_pkg ON tt.product_id = p_pkg.product_id
        LEFT JOIN products p ON bi.product_id = p.product_id
        WHERE bi.booking_id IN ($inQuery)
    ";
    $stmtItems = $pdo->prepare($sqlItems);
    $stmtItems->execute($booking_ids);
    $all_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $items_by_booking = [];
    $addons_qty_by_booking = [];
    $total_qty_by_booking = [];

    // BUILD LOOKUP: All product_ids that are ticket/package products (NOT add-ons)
    // This includes: DP-RES, DP-FULL, DP-NON, FAM-PKG, etc. (category_id = 1 for General Admission)
    $ticketProductIds = [];
    try {
        // Get all product_ids from ticket_types table
        $stmtTkPids = $pdo->query("SELECT DISTINCT product_id FROM ticket_types WHERE product_id IS NOT NULL");
        while ($r = $stmtTkPids->fetch(PDO::FETCH_ASSOC)) {
            $ticketProductIds[] = strtoupper($r['product_id']);
        }
        // Get all product_ids from product_bundle_configs (like FAM-PKG)
        $stmtBundlePids = $pdo->query("SELECT DISTINCT main_product_id FROM product_bundle_configs WHERE is_active = 1");
        while ($r = $stmtBundlePids->fetch(PDO::FETCH_ASSOC)) {
            $ticketProductIds[] = strtoupper($r['main_product_id']);
        }
        // Also include products in category_id = 1 (General Admission)
        $stmtCat1 = $pdo->query("SELECT product_id FROM products WHERE category_id = 1");
        while ($r = $stmtCat1->fetch(PDO::FETCH_ASSOC)) {
            $ticketProductIds[] = strtoupper($r['product_id']);
        }
    } catch (Exception $e) {}
    $ticketProductIds = array_unique($ticketProductIds);

    foreach ($all_items as $item) {
        $bid = $item['booking_id'];
        $name = $item['item_name'];
        $qty = (int)$item['quantity'];
        $pid = $item['product_id'];
        $price = (float)$item['transaction_price'];
        
        // --- BAGONG LOGIC PARA SA 3 HOURS PASS (WEEKDAYS AT WEEKENDS) ---
        if (stripos($name, '3 hours') !== false && stripos($name, 'pass') !== false && stripos($name, 'adult') !== false) {
            // Alamin kung ito ay Weekend o Weekday
            $dayType = (stripos($name, 'weekend') !== false) ? 'Weekends' : 'Weekdays';
            
            // TANDAAN: Palitan ang "50" ng totoong presyo ng Adult WITH SWIM ticket.
            if ($price >= 50) { 
                $name = "3 Hours $dayType Pass Ticket - Adult with swim";
            } else {
                $name = "3 Hours $dayType Pass Ticket - Adult without swim";
            }
        }
        // -----------------------------------------------------------------

        $subtotal = $qty * $price;

        if (!isset($items_by_booking[$bid])) $items_by_booking[$bid] = [];
        if (!isset($total_qty_by_booking[$bid])) $total_qty_by_booking[$bid] = 0;
        
        $items_by_booking[$bid][] = $name . " (x" . $qty . ")";
        $total_qty_by_booking[$bid] += $qty;

        // Categorize into breakdown blocks
        $pidUpper = strtoupper($pid);
        if (strpos($pid, 'QRN_') === 0 || strpos($pid, 'QRR_') === 0) {
            if (!isset($qr_breakdown[$name])) $qr_breakdown[$name] = ['qty' => 0, 'price' => $price, 'total' => 0.00];
            $qr_breakdown[$name]['qty'] += $qty;
            $qr_breakdown[$name]['price'] = $price;
            $qr_breakdown[$name]['total'] += $subtotal;
        } elseif (strpos(strtolower($name), 'annual') !== false || strpos($pidUpper, 'AP') === 0) {
            if (!isset($annual_passes_breakdown[$name])) $annual_passes_breakdown[$name] = ['qty' => 0, 'price' => $price, 'total' => 0.00];
            $annual_passes_breakdown[$name]['qty'] += $qty;
            $annual_passes_breakdown[$name]['price'] = $price;
            $annual_passes_breakdown[$name]['total'] += $subtotal;
        } elseif (strpos($pid, 'type_') === 0 || in_array($pidUpper, $ticketProductIds)) {
            // FIXED: Now also catches FAM-PKG, DP-RES, DP-FULL, DP-NON directly
            if (!isset($tickets_breakdown[$name])) $tickets_breakdown[$name] = ['qty' => 0, 'price' => $price, 'total' => 0.00];
            $tickets_breakdown[$name]['qty'] += $qty;
            $tickets_breakdown[$name]['price'] = $price;
            $tickets_breakdown[$name]['total'] += $subtotal;
        } else {
            if (!isset($addons_breakdown[$name])) $addons_breakdown[$name] = ['qty' => 0, 'price' => $price, 'total' => 0.00];
            $addons_breakdown[$name]['qty'] += $qty;
            $addons_breakdown[$name]['price'] = $price;
            $addons_breakdown[$name]['total'] += $subtotal;
        }

        if ($item['category_id'] == 6) {
            if (!isset($addons_qty_by_booking[$bid])) $addons_qty_by_booking[$bid] = 0;
            $addons_qty_by_booking[$bid] += $qty;
        }
    }


    // Fetch Topup Logs
    $topups_by_booking = [];
    if ($addonLogsEnabled) {
        $sqlTopups = "
            SELECT booking_id, COUNT(*) as topup_count, MAX(created_at) as last_purchase
            FROM addon_purchase_logs
            WHERE booking_id IN ($inQuery) AND action='TOPUP' AND payment_status='paid'
            GROUP BY booking_id
        ";
        $stmtTopups = $pdo->prepare($sqlTopups);
        $stmtTopups->execute($booking_ids);
        $all_topups = $stmtTopups->fetchAll(PDO::FETCH_ASSOC);
        foreach ($all_topups as $tu) {
            $topups_by_booking[$tu['booking_id']] = $tu;
        }
    }

    // Fetch Ticket Codes
    $sqlTix = "SELECT booking_id, ticket_code FROM ticket_instances WHERE booking_id IN ($inQuery)";
    $stmtTix = $pdo->prepare($sqlTix);
    $stmtTix->execute($booking_ids);
    $all_tix = $stmtTix->fetchAll(PDO::FETCH_ASSOC);
    
    $tix_by_booking = [];
    foreach ($all_tix as $tix) {
        $bid = $tix['booking_id'];
        if (!isset($tix_by_booking[$bid])) $tix_by_booking[$bid] = [];
        $tix_by_booking[$bid][] = $tix['ticket_code'];
    }


    // Process Final Data and Calculate Totals
    foreach ($sales as &$row) {
        $bid = $row['booking_id'];
        
        $row['total_qty'] = $total_qty_by_booking[$bid] ?? 0;
        $row['item_summary'] = isset($items_by_booking[$bid]) ? implode(", ", $items_by_booking[$bid]) : 'No items';
        $row['addon_qty'] = $addons_qty_by_booking[$bid] ?? 0;
        $row['has_addons'] = ($row['addon_qty'] > 0);
        
        if (isset($topups_by_booking[$bid])) {
            $row['topup_count'] = $topups_by_booking[$bid]['topup_count'];
            $row['last_addon_purchase_at'] = $topups_by_booking[$bid]['last_purchase'];
        } else {
            $row['topup_count'] = 0;
            $row['last_addon_purchase_at'] = null;
        }

        $row['ticket_codes'] = isset($tix_by_booking[$bid]) ? implode(", ", $tix_by_booking[$bid]) : 'N/A';
        $row['received_by'] = (!empty($row['cashier_name'])) ? $row['cashier_name'] : 'Online / System';

        $totalRevenue += $row['total_amount'];
        $totalTicketsItemsQty += $row['total_qty'];

        // Determine if this booking was received by reception (manual) or online (system)
        $is_manual = !empty($row['cashier_name']);

        $pm = strtolower($row['payment_method'] ?? '');
        if ($pm === 'cash') {
            $cash_count++;
            $cash_total += $row['total_amount'];
            $row['formatted_payment'] = 'CASH';
        } elseif (in_array($pm, ['card', 'credit card', 'credit_card', 'stripe'])) {
            $card_count++;
            $card_total += $row['total_amount'];
            // NEW: Split card into Online vs Manual
            if ($is_manual) {
                $card_manual_count++;
                $card_manual_total += $row['total_amount'];
                $row['formatted_payment'] = 'CARD (Manual)';
            } else {
                $card_online_count++;
                $card_online_total += $row['total_amount'];
                $row['formatted_payment'] = 'CARD (Online)';
            }
        } elseif (in_array($pm, ['qr_points', 'qr_wallet', 'qr payment'])) {
            $qr_count++;
            $qr_total += $row['total_amount'];
            $row['formatted_payment'] = 'QR PAYMENT';
        } elseif (strpos($pm, 'cash:') !== false || strpos($pm, 'card:') !== false) {
            $row['formatted_payment'] = strtoupper($row['payment_method']);
            preg_match('/cash:\s*([\d\.]+)/i', $pm, $cash_match);
            preg_match('/card:\s*([\d\.]+)/i', $pm, $card_match);
            $extracted_cash = isset($cash_match[1]) ? (float)$cash_match[1] : 0;
            $extracted_card = isset($card_match[1]) ? (float)$card_match[1] : 0;
            if ($extracted_cash > 0) { $cash_total += $extracted_cash; $cash_count++; }
            if ($extracted_card > 0) {
                $card_total += $extracted_card; $card_count++;
                if ($is_manual) { $card_manual_total += $extracted_card; $card_manual_count++; }
                else { $card_online_total += $extracted_card; $card_online_count++; }
            }
        } else {
            $other_count++;
            $other_total += $row['total_amount'];
            $row['formatted_payment'] = strtoupper($pm ?: 'UNKNOWN');
        }
    }
    unset($row);
}

if(!empty($tickets_breakdown)) ksort($tickets_breakdown);
if(!empty($annual_passes_breakdown)) ksort($annual_passes_breakdown);
if(!empty($qr_breakdown)) ksort($qr_breakdown);
if(!empty($addons_breakdown)) ksort($addons_breakdown);


// ==========================================
// EXCEL EXPORT
// ==========================================
if ($action === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Sales_Report_' . $type . '_' . date('Ymd') . '.csv');
    $output = fopen('php://output', 'w');
    
    fputcsv($output, [
        'Booking ID', 'Customer Name', 'Email', 'Phone', 'Payment Method', 'Amount (AED)', 
        'Date Paid', 'Received By', 'Visit Date', 'Used Tickets', 'Pass Visits', 
        'Add-ons Bought', 'Top-ups Made', 'Total Qty', 'Items/Tickets Bought', 'Individual Ticket Codes'
    ]);
    
    foreach ($sales as $row) {
        $phone_code = $row['phone_code'] ?? '';
        $customer_phone = $row['customer_phone'] ?? '';
        $full_phone = trim($phone_code . ' ' . $customer_phone);
        $visit_date_formatted = !empty($row['visit_date']) ? date('Y-m-d', strtotime($row['visit_date'])) : 'N/A';
        
        fputcsv($output, [
            $row['booking_id'], $row['customer_name'], $row['customer_email'], $full_phone,
            $row['formatted_payment'], $row['total_amount'], $row['created_at'], $row['received_by'],
            $visit_date_formatted, $row['used_tix_count'], $row['pass_visit_count'], $row['addon_qty'],
            $row['topup_count'], $row['total_qty'], $row['item_summary'], $row['ticket_codes']
        ]);
    }

    fputcsv($output, []); 
    fputcsv($output, ['', '', '', '', 'FINAL REPORT SUMMARY']);
    fputcsv($output, ['', '', '', '', 'TOTAL REVENUE:', $totalRevenue, 'AED']);
    fputcsv($output, ['', '', '', '', 'TOTAL TICKETS/ITEMS:', $totalTicketsItemsQty, 'units']);
    fputcsv($output, ['', '', '', '', 'CASH TOTAL:', $cash_total, 'AED', "($cash_count txns)"]);
    fputcsv($output, ['', '', '', '', 'CARD TOTAL (All):', $card_total, 'AED', "($card_count txns)"]);
    fputcsv($output, ['', '', '', '', '  - Card (Online/System):', $card_online_total, 'AED', "($card_online_count txns)"]);
    fputcsv($output, ['', '', '', '', '  - Card (Manual/Reception):', $card_manual_total, 'AED', "($card_manual_count txns)"]);
    fputcsv($output, ['', '', '', '', 'QR PAYMENT TOTAL:', $qr_total, 'AED', "($qr_count txns)"]);
    
    fclose($output);
    exit;
}


// ==========================================
// PRINT / HTML VIEW
// ==========================================
?>
<!DOCTYPE html>
<html>
<head>
    <title>Sales Report</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; font-size: 13px; color: #333;}
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; vertical-align: top; }
        th { background-color: #f4f4f4; color: #003B72;}
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #003B72; padding-bottom: 10px;}
        .summary-text { font-size: 0.85em; color: #555; display: block; margin-top: 5px; line-height: 1.4;}
        .badge-used { display: inline-block; background: #d4edda; color: #155724; padding: 3px 6px; border-radius: 4px; font-size: 0.8em; font-weight: bold; margin-top: 4px; }
        .addon-note { font-size: 0.82em; font-weight: bold; color: #ff7a00; margin-top: 6px; display: block; }
        .summary-wrapper { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 35px; width: 100%; }
        .summary-box { background: #ffffff; border: 2px solid #003B72; border-radius: 12px; padding: 16px; box-sizing: border-box; break-inside: avoid; }
        .summary-box h3 { margin: 0 0 10px 0; color: #003B72; font-size: 13px; border-bottom: 2px solid #003B72; padding-bottom: 5px; text-transform: uppercase; letter-spacing: 0.5px; }
        .summary-table { width: 100%; margin: 0; }
        .summary-table td { border: none; padding: 6px 4px; font-size: 13px; }
        .summary-table .main-row { font-weight: bold; font-size: 15px; background: #e3f2fd; }
        .summary-table .main-row td { padding: 8px 6px; }
        @media print { .no-print { display: none; } .summary-box { break-inside: avoid; page-break-inside: avoid; } }
    </style>
</head>
<body>
    <div class="header">
        <h2 style="margin:0;">Sales Report - Ajman Water Park</h2>
        <div class="no-print" style="display:flex; gap:10px;">
            <button onclick="window.location.href='reception_dashboard.php?view=sales_report'" style="background:#6c757d; color:white; border:none; padding:10px 20px; font-size:14px; border-radius:5px; cursor:pointer;">&#8592; Back to Pos</button>
            <button onclick="window.print()" style="background:#003B72; color:white; border:none; padding:10px 20px; font-size:14px; border-radius:5px; cursor:pointer;">Print Report</button>
        </div>
    </div>
    <p style="font-size: 16px;"><strong><?php echo $dateRangeStr; ?></strong></p>


    <table>
        <thead>
            <tr>
                <th width="7%">ID</th>
                <th width="14%">Customer</th>
                <th width="10%">Payment</th>
                <th width="18%">Package Summary (Qty)</th>
                <th width="15%">Ticket Codes</th>
                <th width="13%">Date Paid & Receiver</th>
                <th width="13%">Visit Details</th>
                <th width="10%">Amount (AED)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sales as $row): ?>
            <tr>
                <td><strong>#<?php echo str_pad($row['booking_id'], 6, '0', STR_PAD_LEFT); ?></strong></td>
                <td>
                    <strong><?php echo htmlspecialchars($row['customer_name']); ?></strong><br>
                    <span style="font-size:0.85em; color:#777;"><?php echo htmlspecialchars($row['customer_email']); ?></span><br>
                    <span style="font-size:0.85em; color:#333;">&#9742; <?php echo htmlspecialchars(trim(($row['phone_code'] ?? '') . ' ' . ($row['customer_phone'] ?? ''))); ?></span>
                </td>
                <td style="font-weight: bold; color: #6f42c1; font-size: 11px; line-height:1.2; max-width:130px; word-wrap:break-word;"><?php echo $row['formatted_payment']; ?></td>
                <td>
                    <strong>Total: <?php echo $row['total_qty']; ?> item(s)</strong>
                    <span class="summary-text"><?php echo htmlspecialchars($row['item_summary']); ?></span>
                    <?php if($row['has_addons']): ?>
                        <span class="addon-note">Add-ons: YES (x<?php echo $row['addon_qty']; ?>)
                            <?php if($row['topup_count'] > 0): ?><br>Top-ups: <?php echo $row['topup_count']; ?><?php endif; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td><span class="summary-text"><?php echo htmlspecialchars($row['ticket_codes']); ?></span></td>
                <td>
                    <?php echo date('M d, Y', strtotime($row['created_at'])); ?><br>
                    <span style="font-size:0.85em; color:#777;"><?php echo date('h:i A', strtotime($row['created_at'])); ?></span><br>
                    <span style="font-size:0.85em; color:#003B72; font-weight:bold; display:block; margin-top:5px;">By: <?php echo htmlspecialchars($row['received_by']); ?></span>
                </td>
                <td>
                    <strong><?php echo !empty($row['visit_date']) ? date('M d, Y', strtotime($row['visit_date'])) : 'N/A'; ?></strong><br>
                    <?php if ($row['used_tix_count'] > 0): ?><span class="badge-used"><?php echo $row['used_tix_count']; ?> Used Tix</span><?php endif; ?>
                    <?php if ($row['pass_visit_count'] > 0): ?><span class="badge-used"><?php echo $row['pass_visit_count']; ?> Pass Visit</span><?php endif; ?>
                </td>
                <td style="font-weight:bold; color:#155724; font-size: 14px;">AED <?php echo number_format($row['total_amount'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(count($sales) == 0): ?>
                <tr><td colspan="8" style="text-align:center; padding: 30px; color:#999;">No sales found for this period.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>


    <div class="summary-wrapper">
        
        <div class="summary-box">
            <h3>Payment Methods Summary</h3>
            <table class="summary-table">
                <tr class="main-row">
                    <td>TOTAL REVENUE:</td>
                    <td style="text-align: right; color: #003B72;">AED <?php echo number_format($totalRevenue, 2); ?></td>
                </tr>
                <tr style="border-bottom: 1px dashed #ccc;">
                    <td>Total Items Ordered:</td>
                    <td style="text-align: right; font-weight: bold; color: #333;"><?php echo number_format($totalTicketsItemsQty); ?> pcs</td>
                </tr>
                <tr>
                    <td>Cash Total:</td>
                    <td style="text-align: right; font-weight: bold; color: #28a745;">AED <?php echo number_format($cash_total, 2); ?> <span style="font-size:11px; color:#666; font-weight:normal;">(<?php echo $cash_count; ?> txns)</span></td>
                </tr>
                <tr style="background:#fffbea;">
                    <td colspan="2" style="font-weight:bold; color:#003B72; padding-top:10px;">Card Payments Breakdown:</td>
                </tr>
                <tr>
                    <td style="padding-left:15px;">Card Total (All):</td>
                    <td style="text-align: right; font-weight: bold; color: #f39c12;">AED <?php echo number_format($card_total, 2); ?> <span style="font-size:11px; color:#666; font-weight:normal;">(<?php echo $card_count; ?> txns)</span></td>
                </tr>
                <tr>
                    <td style="padding-left:30px; color:#0d6efd;">Card (Online/System):</td>
                    <td style="text-align: right; font-weight: bold; color: #0d6efd;">AED <?php echo number_format($card_online_total, 2); ?> <span style="font-size:11px; color:#666; font-weight:normal;">(<?php echo $card_online_count; ?> txns)</span></td>
                </tr>
                <tr>
                    <td style="padding-left:30px; color:#e65100;">Card (Manual/Reception):</td>
                    <td style="text-align: right; font-weight: bold; color: #e65100;">AED <?php echo number_format($card_manual_total, 2); ?> <span style="font-size:11px; color:#666; font-weight:normal;">(<?php echo $card_manual_count; ?> txns)</span></td>
                </tr>
                <tr>
                    <td>QR Payment Total:</td>
                    <td style="text-align: right; font-weight: bold; color: #0ea5e9;">AED <?php echo number_format($qr_total, 2); ?> <span style="font-size:11px; color:#666; font-weight:normal;">(<?php echo $qr_count; ?> txns)</span></td>
                </tr>
            </table>
        </div>


        <?php if (in_array('tickets', $allowed_cats)): ?>
        <div class="summary-box">
            <h3>Tickets Volume Breakdown</h3>
            <table class="summary-table">
                <thead>
                    <tr style="border-bottom: 2px solid #ddd; font-weight: bold; font-size: 11px; color: #555;">
                        <td>Ticket Package Type</td>
                        <td style="text-align: center; width: 12%;">Qty</td>
                        <td style="text-align: right; width: 22%;">Price</td>
                        <td style="text-align: right; width: 25%;">Total</td>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets_breakdown as $itemName => $data): ?>
                    <tr style="border-bottom: 1px solid #f2f2f2;">
                        <td><?php echo htmlspecialchars($itemName); ?></td>
                        <td style="text-align: center; font-weight: bold;"><?php echo $data['qty']; ?></td>
                        <td style="text-align: right; color: #666; font-size: 12px;">AED <?php echo number_format($data['price'], 2); ?></td>
                        <td style="text-align: right; font-weight: bold; color: #003B72;">AED <?php echo number_format($data['total'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (in_array('annual_passes', $allowed_cats)): ?>
        <div class="summary-box">
            <h3>Annual Passes Breakdown</h3>
            <table class="summary-table">
                <thead>
                    <tr style="border-bottom: 2px solid #ddd; font-weight: bold; font-size: 11px; color: #555;">
                        <td>Annual Pass Type</td>
                        <td style="text-align: center; width: 12%;">Qty</td>
                        <td style="text-align: right; width: 22%;">Price</td>
                        <td style="text-align: right; width: 25%;">Total</td>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($annual_passes_breakdown as $itemName => $data): ?>
                    <tr style="border-bottom: 1px solid #f2f2f2;">
                        <td><?php echo htmlspecialchars($itemName); ?></td>
                        <td style="text-align: center; font-weight: bold;"><?php echo $data['qty']; ?></td>
                        <td style="text-align: right; color: #666; font-size: 12px;">AED <?php echo number_format($data['price'], 2); ?></td>
                        <td style="text-align: right; font-weight: bold; color: #003B72;">AED <?php echo number_format($data['total'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($annual_passes_breakdown)): ?>
                        <tr><td colspan="4" style="color:#999; font-style:italic; font-size:11px; text-align:center;">No annual passes found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>


        <?php if (in_array('qr_wallet', $allowed_cats)): ?>
        <div class="summary-box">
            <h3>QR Wallet Cards Activity</h3>
            <table class="summary-table">
                <thead>
                    <tr style="border-bottom: 2px solid #ddd; font-weight: bold; font-size: 11px; color: #555;">
                        <td>Card Transaction Type</td>
                        <td style="text-align: center; width: 12%;">Qty</td>
                        <td style="text-align: right; width: 22%;">Price</td>
                        <td style="text-align: right; width: 25%;">Total</td>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($qr_breakdown as $itemName => $data): ?>
                    <tr style="border-bottom: 1px solid #f2f2f2;">
                        <td><?php echo htmlspecialchars($itemName); ?></td>
                        <td style="text-align: center; font-weight: bold;"><?php echo $data['qty']; ?></td>
                        <td style="text-align: right; color: #666; font-size: 12px;">
                            <?php echo ($itemName === 'QR Wallet Top-up') ? 'Varies' : 'AED ' . number_format($data['price'], 2); ?>
                        </td>
                        <td style="text-align: right; font-weight: bold; color: #003B72;">AED <?php echo number_format($data['total'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (in_array('addons', $allowed_cats)): ?>
        <div class="summary-box">
            <h3>Add-ons & Vouchers Volume</h3>
            <table class="summary-table">
                <thead>
                    <tr style="border-bottom: 2px solid #ddd; font-weight: bold; font-size: 11px; color: #555;">
                        <td>Service / Voucher Product</td>
                        <td style="text-align: center; width: 12%;">Qty</td>
                        <td style="text-align: right; width: 22%;">Price</td>
                        <td style="text-align: right; width: 25%;">Total</td>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($addons_breakdown as $itemName => $data): ?>
                    <tr style="border-bottom: 1px solid #f2f2f2;">
                        <td><?php echo htmlspecialchars($itemName); ?></td>
                        <td style="text-align: center; font-weight: bold;"><?php echo $data['qty']; ?></td>
                        <td style="text-align: right; color: #666; font-size: 12px;">AED <?php echo number_format($data['price'], 2); ?></td>
                        <td style="text-align: right; font-weight: bold; color: #ff7a00;">AED <?php echo number_format($data['total'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($addons_breakdown)): ?>
                        <tr><td colspan="4" style="color:#999; font-style:italic; font-size:11px; text-align:center;">No standalone products found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div>
    
    <script> window.onload = function() { window.print(); } </script>
</body>
</html>
