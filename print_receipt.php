<?php
// print_receipt.php
include_once 'db_connect.php';
date_default_timezone_set('Asia/Dubai');

$bookingId = $_GET['booking_id'] ?? '';

if (empty($bookingId)) {
    die("Error: No Booking ID provided.");
}

// Helper na kapareho ng email_helper.php
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// 1. KUNIN ANG TRANSACTION DETAILS
$stmt = $pdo->prepare("SELECT * FROM bookings WHERE booking_id = ? LIMIT 1");
$stmt->execute([$bookingId]);
$details = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$details) {
    die("Error: Booking/Transaction not found.");
}

// 2. KUNIN ANG ITEMS
$stmtItems = $pdo->prepare("
    SELECT 
        bi.product_id, 
        bi.quantity, 
        bi.price_per_item AS price,
        COALESCE(tt.category, p.name, bi.product_id) AS category, 
        tt.sub_label,
        p.name AS name
    FROM booking_items bi 
    LEFT JOIN ticket_types tt ON (
        bi.product_id = CONCAT('type_', tt.type_id) OR 
        bi.product_id = CAST(tt.type_id AS CHAR)
    )
    LEFT JOIN products p ON bi.product_id = p.product_id
    WHERE bi.booking_id = ?
");
$stmtItems->execute([$bookingId]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// 2.5 KUNIN ANG ADDON REDEMPTIONS
$stmtRedeem = $pdo->prepare("
    SELECT ar.product_id, ar.quantity_total,
           COALESCE(p.name, ar.product_id) AS name
    FROM addon_redemptions ar
    LEFT JOIN products p ON ar.product_id = p.product_id
    WHERE ar.booking_id = ?
");
$stmtRedeem->execute([$bookingId]);
$redemptions = $stmtRedeem->fetchAll(PDO::FETCH_ASSOC);

$existingByProduct = [];
foreach ($items as $it) {
    $pid = strtoupper((string)($it['product_id'] ?? ''));
    if (!isset($existingByProduct[$pid])) $existingByProduct[$pid] = 0;
    $existingByProduct[$pid] += (int)($it['quantity'] ?? 0);
}

foreach ($redemptions as $r) {
    $pid = strtoupper((string)$r['product_id']);
    $totalQty = (int)$r['quantity_total'];
    $existingQty = $existingByProduct[$pid] ?? 0;
    $bundledQty = $totalQty - $existingQty;

    if ($bundledQty > 0) {
        $items[] = [
            'product_id' => $r['product_id'],
            'quantity'   => $bundledQty,
            'price'      => 0.00,
            'category'   => null,
            'sub_label'  => null,
            'name'       => $r['name'],
            'is_bundled' => true,
        ];
    }
}

// 3. KUNIN ANG TICKET QRs
$stmtTix = $pdo->prepare("SELECT ticket_code, ticket_type FROM ticket_instances WHERE booking_id = ?");
$stmtTix->execute([$bookingId]);
$tickets = $stmtTix->fetchAll(PDO::FETCH_ASSOC);

$customerName = e($details['customer_name'] ?? 'Walk-in Customer');
$cashierName  = e($details['cashier_name'] ?? 'Admin');

// Build full customer phone (handle both old and new format)
$phoneCode    = trim((string)($details['phone_code'] ?? ''));
$phoneNumber  = trim((string)($details['customer_phone'] ?? ''));
if ($phoneCode !== '' && strpos($phoneNumber, '+') !== 0) {
    $customerPhone = e($phoneCode . ' ' . $phoneNumber);
} else {
    $customerPhone = e($phoneNumber !== '' ? $phoneNumber : 'N/A');
}
$date         = date("d-M-Y h:i A", strtotime($details['created_at'] ?? date('Y-m-d H:i:s')));
$visitDateRaw = $details['visit_date'] ?? $details['date_of_visit'] ?? null;
$visitDate    = $visitDateRaw ? date("d-M-Y", strtotime($visitDateRaw)) : "N/A";
$totalAmount  = number_format((float)($details['total_amount'] ?? 0), 2);
$vatAmount    = number_format((float)($details['vat_amount'] ?? 0), 2);

$rawPaymentMethod = $details['payment_method'] ?? 'CASH';
if (strtolower($rawPaymentMethod) === 'qr_points') {
    $paymentMethod = 'QR WALLET / POINTS';
} else {
    $paymentMethod = strtoupper($rawPaymentMethod);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - #<?php echo str_pad($bookingId, 6, '0', STR_PAD_LEFT); ?></title>

    <!-- Google Fonts: Inter (UI text) + Roboto Mono (prices/codes) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Roboto+Mono:wght@400;500;700&display=swap" rel="stylesheet">

    <style>
        body {
            background-color: #e0e0e0;
            padding: 20px;
            font-family: 'Inter', 'Segoe UI', Roboto, Arial, sans-serif;
            font-weight: 500;
            color: #000;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            letter-spacing: 0.1px;
        }
        /* Use the monospace ONLY where alignment matters (codes, totals, prices) */
        .mono { font-family: 'Roboto Mono', 'Consolas', monospace; }

        .receipt-container {
            max-width: 360px;
            margin: 0 auto;
            background: #fff;
            padding: 22px;
            border-radius: 6px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .text-center { text-align: center; }
        .dashed-border-bottom { border-bottom: 1px dashed #000; padding-bottom: 12px; margin-bottom: 12px; }
        .flex-between { display: flex; justify-content: space-between; margin: 3px 0; font-size: 12px; }
        .flex-between strong { font-weight: 600; }

        table { width: 100%; font-size: 12px; border-collapse: collapse; margin-bottom: 10px; }
        th, td { border-bottom: 1px dashed #999; padding: 10px 0; vertical-align: top; }
        th { text-align: left; font-weight: 700; letter-spacing: 0.5px; font-size: 11px; }
        .text-right { text-align: right; }
        .text-center-td { text-align: center; }

        .item-name { font-weight: 600; font-size: 13px; line-height: 1.3; }

        /* Crisp QR rendering: scale down a high-res source without smoothing */
        .qr-img {
            image-rendering: -webkit-optimize-contrast;
            image-rendering: crisp-edges;
        }

        h2.brand {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 0.3px;
        }

        @media print {
            /* Force colors/black to print exactly as shown */
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            body {
                background-color: #fff;
                padding: 0;
                margin: 0;
                color: #000;
                font-size: 12.5px;
                font-weight: 600;            /* slightly bolder body for crisp print */
            }
            .receipt-container {
                box-shadow: none;
                max-width: 100%;
                width: 80mm;                  /* standard thermal receipt width */
                padding: 0;
                margin: 0 auto;
            }
            .no-print { display: none !important; }

            /* Darken everything so faint grays don't disappear */
            table { font-size: 12.5px; }
            th, td { border-bottom: 1px dashed #000; padding: 8px 0; }
            p, span, div, strong, td, th { color: #000 !important; }

            .item-name { font-weight: 700; }
            .qr-label { font-size: 10px !important; color: #000 !important; font-weight: 600 !important; }

            /* Avoid splitting tickets / table rows across pages */
            tr, .ticket-card { page-break-inside: avoid; }
        }
    </style>
</head>
<body>

    <div class="text-center no-print" style="margin-bottom: 20px; display: flex; justify-content: center; gap: 10px;">
        <button onclick="if(window.opener){ window.close(); } else { window.location.href='reception_dashboard.php'; }" style="background: #6c757d; color: #fff; padding: 10px 22px; border: none; font-size: 15px; cursor: pointer; border-radius: 6px; font-weight: 700; font-family: 'Inter', sans-serif; letter-spacing: 0.3px;">
            &#8592; Back to Reception
        </button>
        <button onclick="window.print()" style="background: #003B72; color: #fff; padding: 10px 22px; border: none; font-size: 15px; cursor: pointer; border-radius: 6px; font-weight: 700; font-family: 'Inter', sans-serif; letter-spacing: 0.3px;">
            🖨️ PRINT NOW
        </button>
    </div>

    <div class="receipt-container">

        <div class="text-center dashed-border-bottom">
            <img src="Images/awpemaillogo.png" alt="Ajman Water Park" style="max-width:140px; height:auto; margin:0 auto 8px;">
            <h2 class="brand">Ajman Water Park</h2>
            <p style="font-size:11px; margin:4px 0; font-weight:500;">Waterpark &amp; Resorts, Ajman UAE</p>
            <p style="font-size:11px; margin:0; font-weight:500;">Tel: +971 52 120 7573</p>
        </div>

        <div class="dashed-border-bottom">
            <p class="flex-between"><strong>Order ID:</strong> <span class="mono">#<?php echo str_pad($bookingId, 6, '0', STR_PAD_LEFT); ?></span></p>
            <p class="flex-between"><strong>Purchase Date:</strong> <span class="mono"><?php echo $date; ?></span></p>
            <p class="flex-between"><strong>Date of Visit:</strong> <span class="mono"><?php echo $visitDate; ?></span></p>
            <p class="flex-between"><strong>Person on Duty:</strong> <span><?php echo $cashierName; ?></span></p>
            <p class="flex-between"><strong>Customer:</strong> <span><?php echo $customerName; ?></span></p>
            <p class="flex-between"><strong>Phone:</strong> <span class="mono"><?php echo $customerPhone; ?></span></p>
        </div>

        <table>
            <thead>
                <tr>
                    <th>ITEM</th>
                    <th class="text-center-td">QTY</th>
                    <th class="text-right">AMT</th>
                </tr>
            </thead>
            <tbody>
            <?php 
            foreach ($items as $item):
                $qty = max(1, (int)($item['quantity'] ?? 0));
                $price = (float)($item['price'] ?? 0);
                $rowTotalValue = $price * $qty;
                $rowTotal = number_format($rowTotalValue, 2);

                $productIdRaw = strtoupper((string)($item['product_id'] ?? ''));

                $cat = $item['category'] ?? '';
                $sub = $item['sub_label'] ?? '';
                $combinedName = trim($cat . ' ' . $sub);

                if (!empty($item['name']) && $item['name'] !== 'Item') {
                    $itemName = $item['name'];
                } elseif (!empty($combinedName)) {
                    $itemName = $combinedName;
                } else {
                    $itemName = $productIdRaw;
                }

                switch ($productIdRaw) {
                    case 'ADD1': $itemName = "Parking"; break;
                    case 'ADD2': $itemName = "Zipline"; break;
                    case 'ADD3': $itemName = "Locker"; break;
                    case 'ADD4': $itemName = "Hanging Bridge"; break;
                    case 'ADD5': $itemName = "Photography"; break;
                    case 'ADD6': $itemName = "Meal Voucher"; break;
                    default:
                        if (strpos($productIdRaw, 'QRN_') === 0) {
                            $itemName = "New QR Wallet Card";
                        } elseif (strpos($productIdRaw, 'QRR_') === 0) {
                            $itemName = "QR Wallet Reload";
                        } elseif (empty($itemName) || $itemName == $productIdRaw) {
                            if (strpos($productIdRaw, 'TYPE_') === 0) {
                                $itemName = "Entrance Ticket (" . str_replace('TYPE_', '', $productIdRaw) . ")";
                            } else {
                                $itemName = "Item: " . $productIdRaw;
                            }
                        }
                        break;
                }

                $itemNameSafe = e($itemName);
                if (!empty($item['is_bundled'])) {
                    $itemNameSafe .= " <span style='color:#16a34a; font-size:10px; font-weight:600;'>(Family Package - Included)</span>";
                }
                $uniqueItemCode = $bookingId . '-' . $productIdRaw;

                $isAddon = (strpos($productIdRaw, 'ADD') === 0);
                $qrCodeHtml = '';
                if ($isAddon) {
                    $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=500x500&margin=2&data=" . urlencode($uniqueItemCode);
                    $qrCodeHtml = "
                    <br>
                    <div style='margin-top:6px; border:1px solid #000; display:inline-block; padding:5px; background:#fff;'>
                        <img class='qr-img' src='{$qrUrl}' style='width:110px; height:110px; display:block;' alt='Add-on QR'>
                        <div class='qr-label mono' style='font-size:9px; color:#000; margin-top:3px; font-weight:600;'>Add-on: " . e($uniqueItemCode) . "</div>
                    </div>";
                }
            ?>
            <tr>
                <td>
                    <span class="item-name"><?php echo $itemNameSafe; ?></span>
                    <?php echo $qrCodeHtml; ?>
                </td>
                <td class="text-center-td mono"><?php echo $qty; ?></td>
                <td class="text-right mono">AED <?php echo $rowTotal; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div style="border-top:1px dashed #000; padding-top:12px;">
            <div class="flex-between" style="font-size:12px; margin-bottom:6px;">
                <span>VAT (5% included):</span>
                <span class="mono">AED <?php echo $vatAmount; ?></span>
            </div>
            <div style="font-size:17px; margin-bottom:10px; font-weight:800; display:flex; justify-content:space-between;">
                <span>GRAND TOTAL</span>
                <span class="mono">AED <?php echo $totalAmount; ?></span>
            </div>
            <div class="flex-between">
                <span>Payment Method:</span>
                <span style="font-weight:700;"><?php echo e($paymentMethod); ?></span>
            </div>
        </div>

        <?php if (!empty($tickets)): ?>
        <div style="text-align:center; border-top:2px dashed #000; margin-top:16px; padding-top:16px;">
            <h3 style="margin:0 0 8px 0; color:#003B72; font-family: 'Inter', sans-serif; font-weight:800; letter-spacing:0.5px; font-size:15px;">YOUR ENTRANCE TICKETS</h3>
            <p style="font-size:11px; margin-bottom:14px; color:#444; font-weight:500;">Scan each code individually at the Turnstile Gate.</p>

            <?php foreach ($tickets as $t): 
                $ticketCode = e($t['ticket_code'] ?? '');
                $ticketType = e($t['ticket_type'] ?? '');
                $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=500x500&margin=2&data=" . urlencode($ticketCode);
            ?>
            <div class="ticket-card" style="display:inline-block; vertical-align:top; margin:5px; padding:10px; border:1px solid #000; border-radius:8px; background:#fff; width:120px;">
                <img class="qr-img" src="<?php echo $qrUrl; ?>" style="width:100px; height:100px; display:block; margin:0 auto;" alt="Entry Ticket QR">
                <div style="font-weight:700; font-size:12px; margin-top:8px; color:#003B72; text-transform:uppercase; letter-spacing:0.3px;"><?php echo $ticketType; ?></div>
                <div class="qr-label mono" style="font-size:10px; color:#000; margin-top:3px; word-break:break-all; font-weight:600;"><?php echo $ticketCode; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div style="text-align:center; margin-top:20px; padding-top:12px; border-top:1px solid #000; font-size:11px; color:#000;">
            <p style="margin-bottom:5px; font-weight:700;">Scan tickets above at entrance.</p>
            <p style="margin:5px 0; font-weight:500;">Thank you for visiting!</p>
            <p style="margin:0; font-weight:600;" class="mono">www.ajmanwaterpark.com</p>
        </div>

    </div>

</body>
</html>
