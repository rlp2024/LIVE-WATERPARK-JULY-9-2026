<?php
session_start();
include_once 'db_connect.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

$customerName = trim($input['customer_name'] ?? '');
$customerEmail = trim($input['customer_email'] ?? 'pos@ajmanwaterpark.com');
$customerPhone = trim($input['customer_phone'] ?? '');
$paymentMethod = trim($input['payment_method'] ?? 'Cash');
$totalAmount = floatval($input['total_amount'] ?? 0);
$vatAmount = floatval($input['vat_amount'] ?? 0);
$items = $input['items'] ?? [];
$visitDate = $input['visit_date'] ?? date('Y-m-d');
$linkedBookingId = $input['linked_booking_id'] ?? null;

if (empty($customerName) || empty($items)) {
    echo json_encode(['success' => false, 'error' => 'Customer name and items are required']);
    exit;
}

$cashierName = $_SESSION['admin_fullname'] ?? $_SESSION['full_name'] ?? 'POS Cashier';

try {
    $pdo->beginTransaction();

    // Insert into bookings table
    $stmtBooking = $pdo->prepare("
        INSERT INTO bookings 
        (customer_name, customer_email, customer_phone, total_amount, payment_status, 
         payment_method, visit_date, vat_amount, cashier_name, paid_at, agreed_terms, agreed_refund_policy)
        VALUES (?, ?, ?, ?, 'paid', ?, ?, ?, ?, NOW(), 1, 1)
    ");
    $stmtBooking->execute([
        $customerName,
        $customerEmail,
        $customerPhone,
        $totalAmount,
        $paymentMethod,
        $visitDate,
        $vatAmount,
        $cashierName
    ]);
    $bookingId = $pdo->lastInsertId();

    // Insert booking items and generate ticket instances
    $stmtItem = $pdo->prepare("
        INSERT INTO booking_items (booking_id, product_id, quantity, price_per_item)
        VALUES (?, ?, ?, ?)
    ");

    $stmtTicket = $pdo->prepare("
        INSERT INTO ticket_instances (booking_id, ticket_code, ticket_type, status, is_used)
        VALUES (?, ?, ?, 'unused', 0)
    ");

    $ticketSeq = 1;
    $comboMealQty = 0;

    foreach ($items as $item) {
        $productId = $item['product_id'] ?? '';
        $qty = intval($item['qty'] ?? 1);
        $price = floatval($item['price'] ?? 0);
        $itemType = $item['type'] ?? '';
        $itemName = $item['name'] ?? '';

        // Determine product_id for booking_items
        // For regular tickets, use type_id to map back
        $typeId = intval($item['type_id'] ?? 0);
        $dbProductId = $productId;

        // If it is a ticket type, use the type_id reference
        if ($typeId > 0 && empty($dbProductId)) {
            $stmtLookup = $pdo->prepare("SELECT product_id FROM ticket_types WHERE type_id = ?");
            $stmtLookup->execute([$typeId]);
            $row = $stmtLookup->fetch();
            if ($row) $dbProductId = $row['product_id'];
        }

        // Insert booking item
        $stmtItem->execute([$bookingId, $dbProductId, $qty, $price]);

        // Generate ticket instances for ticket types (not add-ons, not topups)
        if (in_array($itemType, ['adult', 'child', 'bundle'])) {
            // Determine ticket_type label
            $ticketTypeLabel = 'General';
            if ($typeId > 0) {
                $stmtTypeLabel = $pdo->prepare("SELECT category, sub_label FROM ticket_types WHERE type_id = ?");
                $stmtTypeLabel->execute([$typeId]);
                $typeRow = $stmtTypeLabel->fetch();
                if ($typeRow) {
                    $ticketTypeLabel = $typeRow['category'];
                    if ($typeRow['sub_label']) $ticketTypeLabel .= ' ' . $typeRow['sub_label'];
                }
            } elseif ($itemType === 'bundle') {
                // For family package bundle, generate 2 adult + 2 kids tickets
                for ($i = 0; $i < 2; $i++) {
                    $code = $bookingId . '-' . str_pad($ticketSeq, 2, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
                    $stmtTicket->execute([$bookingId, $code, 'Adult']);
                    $ticketSeq++;
                }
                for ($i = 0; $i < 2; $i++) {
                    $code = $bookingId . '-' . str_pad($ticketSeq, 2, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
                    $stmtTicket->execute([$bookingId, $code, 'Kids']);
                    $ticketSeq++;
                }
                // Track combo meals from bundle (4 included)
                $comboMealQty += (4 * $qty);
                continue; // Skip the normal ticket generation below
            }

            for ($i = 0; $i < $qty; $i++) {
                $code = $bookingId . '-' . str_pad($ticketSeq, 2, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
                $stmtTicket->execute([$bookingId, $code, $ticketTypeLabel]);
                $ticketSeq++;
            }
        }

        // Track ADD6 (Combo Meal) quantities
        if ($dbProductId === 'ADD6') {
            $comboMealQty += $qty;
        }
    }

    // Insert addon_redemptions for combo meals (ADD6)
    if ($comboMealQty > 0) {
        $uniqueCode = $bookingId . '-ADD6';
        $stmtAddonRedeem = $pdo->prepare("
            INSERT INTO addon_redemptions (booking_id, product_id, unique_code, status, quantity_total, quantity_used)
            VALUES (?, 'ADD6', ?, 'unused', ?, 0)
        ");
        $stmtAddonRedeem->execute([$bookingId, $uniqueCode, $comboMealQty]);
    }

    // Insert addon_purchase_logs
    $itemsSummary = '';
    foreach ($items as $item) {
        $itemsSummary .= ($item['qty'] ?? 1) . 'x ' . ($item['name'] ?? '') . ', ';
    }
    $itemsSummary = rtrim($itemsSummary, ', ');

    $stmtLog = $pdo->prepare("
        INSERT INTO addon_purchase_logs 
        (booking_id, action, payment_status, payment_method, total_amount, vat_amount, items_summary, created_by)
        VALUES (?, 'BOOKING', 'pending', ?, ?, ?, ?, ?)
    ");
    $stmtLog->execute([
        $bookingId,
        strtolower($paymentMethod),
        $totalAmount,
        $vatAmount,
        $itemsSummary,
        $cashierName
    ]);

    $pdo->commit();
    echo json_encode(['success' => true, 'booking_id' => $bookingId]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
