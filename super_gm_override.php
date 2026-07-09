<?php
// super_gm_override.php - SMART AUTO-SCANNING RETRIEVAL DESK (v6 + Free Addon Injection)
session_start();
include_once 'db_connect.php';
include_once 'email_helper.php';

date_default_timezone_set('Asia/Dubai');

// LIVE GATEWAY CREDENTIALS SYNCHRONIZED FROM SUCCESS.PHP
define('NGENIUS_API_KEY', 'NTc0MjQ4MGUtMTQ4OC00NzYzLTgxODktODgzYjQ3ZGZkZjQ2OjllMDVlMjczLWUxYjQtNDliZS04MDg1LWUyYmYyNmE5YTg3MQ==');
define('NGENIUS_OUTLET_REF', '4a6f1b5e-610f-4440-8e3c-4fb6ddff7d1a');
define('NGENIUS_AUTH_URL', 'https://api-gateway.ngenius-payments.com/identity/auth/access-token');
define('NGENIUS_ORDER_URL', 'https://api-gateway.ngenius-payments.com/transactions/outlets/' . NGENIUS_OUTLET_REF . '/orders');

// SECURITY LAYER: Verify access
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

// ==========================================
// REAL-TIME BACKGROUND N-GENIUS SCANNER (AJAX)
// ==========================================
if (isset($_GET['ajax_scan_gateway']) && isset($_GET['booking_id'])) {
    header('Content-Type: application/json');
    $b_id = (int)$_GET['booking_id'];
    try {
        $stmtRef = $pdo->prepare("SELECT transaction_id, total_amount FROM bookings WHERE booking_id = ? LIMIT 1");
        $stmtRef->execute([$b_id]);
        $b_data = $stmtRef->fetch(PDO::FETCH_ASSOC);
        $order_ref = $b_data['transaction_id'] ?? '';
        if (empty($order_ref)) {
            echo json_encode(['success' => false, 'error' => 'No gateway reference code saved for this checkout session.']);
            exit;
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, NGENIUS_AUTH_URL);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "accept: application/vnd.ni-identity.v1+json",
            "authorization: Basic " . NGENIUS_API_KEY,
            "content-type: application/vnd.ni-identity.v1+json"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $token_response = curl_exec($ch);
        curl_close($ch);
        $token_data = json_decode($token_response, true);
        $access_token = $token_data['access_token'] ?? null;

        if (!$access_token) {
            echo json_encode(['success' => false, 'error' => 'Failed to obtain validation token from N-Genius.']);
            exit;
        }
        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, NGENIUS_ORDER_URL . '/' . $order_ref);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $access_token,
            "Accept: application/vnd.ni-payment.v2+json"
        ]);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 15);
        $order_info_json = curl_exec($ch2);
        curl_close($ch2);
        $order_info = json_decode($order_info_json, true);
        $order_state = $order_info['_embedded']['payment'][0]['state'] ?? 'STARTED';
        echo json_encode(['success' => true, 'state' => $order_state, 'reference' => $order_ref]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ==========================================
// LIVE AJAX ITEM BREAKDOWN DECODER
// ==========================================
if (isset($_GET['ajax_fetch_items']) && isset($_GET['booking_id'])) {
    header('Content-Type: application/json');
    $b_id = (int)$_GET['booking_id'];
    try {
        $stmt = $pdo->prepare("SELECT product_id, quantity, price_per_item FROM booking_items WHERE booking_id = ?");
        $stmt->execute([$b_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $decoded_items = [];
        foreach ($items as $item) {
            $name = "Item (" . $item['product_id'] . ")";
            $pid = $item['product_id'];
            if (strpos(strtolower($pid), 'type_') === 0) {
                $typeId = str_replace(['type_', 'TYPE_'], '', $pid);
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
            $decoded_items[] = ['name' => $name, 'qty' => $item['quantity'], 'price' => number_format($item['price_per_item'], 2)];
        }
        echo json_encode(['success' => true, 'items' => $decoded_items]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}


// ==========================================
// AJAX: FETCH CURRENT PAYMENT METHOD (for override tab)
// ==========================================
if (isset($_GET['ajax_get_payment_info']) && isset($_GET['booking_id'])) {
    header('Content-Type: application/json');
    $b_id = (int)$_GET['booking_id'];
    try {
        $stmt = $pdo->prepare("SELECT booking_id, customer_name, customer_email, total_amount, payment_method, payment_status, visit_date, created_at FROM bookings WHERE booking_id = ? LIMIT 1");
        $stmt->execute([$b_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Booking ID not found.']);
        } else {
            echo json_encode(['success' => true, 'booking' => $row]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ==========================================
// AJAX: FETCH FNB ORDER INFO
// ==========================================
if (isset($_GET['ajax_get_fnb_info']) && isset($_GET['order_id'])) {
    header('Content-Type: application/json');
    $o_id = (int)$_GET['order_id'];
    try {
        $stmt = $pdo->prepare("SELECT id, customer_name, customer_email, customer_phone, total_amount, payment_method, payment_status, status, shop_number, created_at, ngenius_ref FROM fnb_orders WHERE id = ? LIMIT 1");
        $stmt->execute([$o_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'F&B Order ID not found.']);
            exit;
        }
        $stmtI = $pdo->prepare("SELECT product_name, quantity, size, price FROM fnb_order_items WHERE order_id = ?");
        $stmtI->execute([$o_id]);
        $items = $stmtI->fetchAll(PDO::FETCH_ASSOC);
        $stmtL = $pdo->prepare("SELECT COUNT(*) FROM fnb_action_log WHERE order_id = ?");
        $stmtL->execute([$o_id]);
        $log_count = (int)$stmtL->fetchColumn();
        echo json_encode(['success' => true, 'order' => $row, 'items' => $items, 'log_count' => $log_count]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ==========================================
// AJAX: LIST RECENT FNB ORDERS
// ==========================================
if (isset($_GET['ajax_list_fnb_orders'])) {
    header('Content-Type: application/json');
    try {
        $filter = $_GET['filter'] ?? 'all';
        $sql = "SELECT id, customer_name, customer_phone, total_amount, payment_method, payment_status, status, shop_number, created_at FROM fnb_orders";
        $where = [];
        if ($filter === 'unpaid') $where[] = "payment_status = 'UNPAID'";
        if ($filter === 'today')  $where[] = "DATE(created_at) = CURDATE()";
        if (!empty($where)) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY id DESC LIMIT 100";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'orders' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ==========================================
// AJAX: MULTI-DELETE F&B ORDERS (checkbox bulk delete)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_multi_delete_fnb'])) {
    header('Content-Type: application/json');
    $ids_raw    = $_POST['order_ids'] ?? [];
    $reason     = trim($_POST['reason'] ?? '');
    $admin_name = $_SESSION['admin_fullname'] ?? $_SESSION['admin_username'] ?? 'Super GM';

    if (!is_array($ids_raw)) { $ids_raw = explode(',', $ids_raw); }
    $order_ids = array_values(array_unique(array_filter(array_map('intval', $ids_raw))));

    if (empty($order_ids)) { echo json_encode(['success' => false, 'error' => 'No orders selected.']); exit; }
    if (empty($reason))    { echo json_encode(['success' => false, 'error' => 'Deletion reason is required for audit trail.']); exit; }

    $deleted = [];
    $failed  = [];
    foreach ($order_ids as $oid) {
        try {
            $stmtSnap = $pdo->prepare("SELECT * FROM fnb_orders WHERE id = ?");
            $stmtSnap->execute([$oid]);
            $snapshot = $stmtSnap->fetch(PDO::FETCH_ASSOC);
            if (!$snapshot) { $failed[] = "$oid (not found)"; continue; }

            $stmtSnapI = $pdo->prepare("SELECT product_name, quantity, size, price FROM fnb_order_items WHERE order_id = ?");
            $stmtSnapI->execute([$oid]);
            $items_snap = $stmtSnapI->fetchAll(PDO::FETCH_ASSOC);
            $items_summary = [];
            foreach ($items_snap as $it) { $items_summary[] = "{$it['quantity']}x {$it['product_name']} @ {$it['price']}"; }
            $items_str = implode(' | ', $items_summary);

            $pdo->beginTransaction();
            $s1 = $pdo->prepare("DELETE FROM fnb_order_items WHERE order_id = ?");
            $s1->execute([$oid]); $items_deleted = $s1->rowCount();
            $s2 = $pdo->prepare("DELETE FROM fnb_action_log WHERE order_id = ?");
            $s2->execute([$oid]); $logs_deleted = $s2->rowCount();
            $s3 = $pdo->prepare("DELETE FROM fnb_orders WHERE id = ?");
            $s3->execute([$oid]);

            try {
                $audit = "FNB ORDER DELETED (BULK) | OrderID: $oid | Customer: {$snapshot['customer_name']} | Total: {$snapshot['total_amount']} | Status: {$snapshot['payment_status']} | Items: $items_str | ItemsDeleted: $items_deleted | LogsDeleted: $logs_deleted | Reason: $reason | By: $admin_name";
                $stmtAud = $pdo->prepare("INSERT INTO admin_audit (admin_name, action_type, target_id, details, created_at) VALUES (?, 'fnb_order_deleted', ?, ?, NOW())");
                $stmtAud->execute([$admin_name, $oid, $audit]);
            } catch (Exception $auditErr) { /* silent */ }

            $pdo->commit();
            $deleted[] = $oid;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $failed[] = "$oid (" . $e->getMessage() . ")";
        }
    }

    echo json_encode(['success' => true, 'deleted_count' => count($deleted), 'deleted_ids' => $deleted, 'failed' => $failed]);
    exit;
}
// =================================================================
// [NEW] AJAX: LIST AVAILABLE ADDON PRODUCTS (category_id = 6)
// =================================================================
if (isset($_GET['ajax_list_addons'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("SELECT product_id, name, price FROM products WHERE category_id = 6 AND is_active = 1 ORDER BY name ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'addons' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// =================================================================
// [NEW] AJAX: FETCH BOOKING + EXISTING ADDONS LOADED
// =================================================================
if (isset($_GET['ajax_get_booking_addons']) && isset($_GET['booking_id'])) {
    header('Content-Type: application/json');
    $b_id = (int)$_GET['booking_id'];
    try {
        $stmt = $pdo->prepare("SELECT booking_id, customer_name, customer_email, customer_phone, total_amount, payment_status, visit_date FROM bookings WHERE booking_id = ? LIMIT 1");
        $stmt->execute([$b_id]);
        $bk = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bk) {
            echo json_encode(['success' => false, 'error' => 'Booking ID not found.']);
            exit;
        }
        // Existing addons loaded sa booking
        $sqlA = "SELECT ar.product_id, ar.unique_code, ar.quantity_total, ar.quantity_used, ar.status, p.name AS product_name
                 FROM addon_redemptions ar
                 LEFT JOIN products p ON ar.product_id = p.product_id
                 WHERE ar.booking_id = ?";
        $stmtA = $pdo->prepare($sqlA);
        $stmtA->execute([$b_id]);
        $addons = $stmtA->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'booking' => $bk, 'existing_addons' => $addons]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$message = "";
$error_message = "";


// ==========================================
// POST CONTROL SUBMISSIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'force_override_existing') {
        $booking_id = (int)$_POST['booking_id'];
        $manual_ref = trim($_POST['manual_reference']);
        if ($booking_id <= 0 || empty($manual_ref)) {
            $error_message = "Please enter both the Target Booking ID and Gateway Reference Code.";
        } else {
            try {
                $pdo->beginTransaction();
                $stmtUpdate = $pdo->prepare("UPDATE bookings SET payment_status = 'paid', transaction_id = ? WHERE booking_id = ?");
                $stmtUpdate->execute([$manual_ref, $booking_id]);
                $pdo->commit();
                $message = "Success! Booking ID #$booking_id is now safely converted to PAID and added to dashboard stats.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error_message = "Database correction failed: " . $e->getMessage();
            }
        }
    }

    if ($action === 'override_payment_method') {
        $booking_id   = (int)$_POST['ovr_booking_id'];
        $method_type  = $_POST['ovr_method_type'] ?? '';
        $cash_amt     = (float)($_POST['ovr_cash_amount'] ?? 0);
        $card_amt     = (float)($_POST['ovr_card_amount'] ?? 0);
        $reason       = trim($_POST['ovr_reason'] ?? '');
        $admin_name   = $_SESSION['admin_fullname'] ?? $_SESSION['admin_username'] ?? 'Super GM';
        if ($booking_id <= 0 || empty($method_type) || empty($reason)) {
            $error_message = "Booking ID, new payment method, and reason are all mandatory.";
        } else {
            $final_method = '';
            switch ($method_type) {
                case 'cash': $final_method = 'Cash'; break;
                case 'card': $final_method = 'Card'; break;
                case 'qr_points': $final_method = 'qr_points'; break;
                case 'mixed':
                    if ($cash_amt <= 0 || $card_amt <= 0) {
                        $error_message = "Mixed payment requires BOTH cash and card amounts (greater than 0).";
                    } else {
                        $final_method = "Cash: " . number_format($cash_amt, 2) . " | Card: " . number_format($card_amt, 2);
                    }
                    break;
                default: $error_message = "Invalid payment method type selected.";
            }
            if (empty($error_message) && !empty($final_method)) {
                try {
                    $stmtOld = $pdo->prepare("SELECT payment_method FROM bookings WHERE booking_id = ?");
                    $stmtOld->execute([$booking_id]);
                    $old_method = $stmtOld->fetchColumn();
                    if ($old_method === false) {
                        $error_message = "Booking ID #$booking_id not found.";
                    } else {
                        $pdo->beginTransaction();
                        $stmtUpd = $pdo->prepare("UPDATE bookings SET payment_method = ? WHERE booking_id = ?");
                        $stmtUpd->execute([$final_method, $booking_id]);
                        try {
                            $audit_note = "PAYMENT METHOD OVERRIDE | OLD: [$old_method] -> NEW: [$final_method] | Reason: $reason | By: $admin_name";
                            $stmtAudit = $pdo->prepare("INSERT INTO admin_audit (admin_name, action_type, target_id, details, created_at) VALUES (?, 'payment_method_override', ?, ?, NOW())");
                            $stmtAudit->execute([$admin_name, $booking_id, $audit_note]);
                        } catch (Exception $auditErr) { /* silent */ }
                        $pdo->commit();
                        $message = "Success! Booking #$booking_id payment method changed from [$old_method] to [$final_method].";
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    $error_message = "Override failed: " . $e->getMessage();
                }
            }
        }
    }


    if ($action === 'execute_manual_pos') {
        $customer_name  = trim($_POST['customer_name']);
        $customer_email = trim($_POST['customer_email']);
        $customer_phone = trim($_POST['customer_phone']);
        $visit_date     = $_POST['visit_date'];
        $ngenius_ref    = trim($_POST['ngenius_reference']);
        $total_amount   = (float)$_POST['hidden_total_amount'];
        $quantities = $_POST['qty'] ?? [];
        $prices     = $_POST['price'] ?? [];
        if (empty($customer_name) || empty($visit_date) || empty($ngenius_ref)) {
            $error_message = "Name, Visit Date, and N-Genius Reference number are mandatory fields.";
        } else {
            try {
                $pdo->beginTransaction();
                $stmtBooking = $pdo->prepare("INSERT INTO bookings (customer_name, customer_email, customer_phone, visit_date, total_amount, payment_status, payment_method, transaction_id, created_at) VALUES (?, ?, ?, ?, ?, 'paid', 'card', ?, NOW())");
                $stmtBooking->execute([$customer_name, $customer_email, $customer_phone, $visit_date, $total_amount, $ngenius_ref]);
                $new_booking_id = $pdo->lastInsertId();
                $item_inserted = false;
                $stmtItem = $pdo->prepare("INSERT INTO booking_items (booking_id, product_id, quantity, price_per_item) VALUES (?, ?, ?, ?)");
                foreach ($quantities as $item_id => $qty) {
                    $qty = (int)$qty;
                    if ($qty > 0) {
                        $price_per_item = (float)($prices[$item_id] ?? 0);
                        $stmtItem->execute([$new_booking_id, $item_id, $qty, $price_per_item]);
                        $item_inserted = true;
                    }
                }
                if (!$item_inserted) {
                    $stmtItem->execute([$new_booking_id, 'MANUAL_OVERRIDE', 1, $total_amount]);
                }
                $pdo->commit();
                $message = "Success! Custom Booking ID #$new_booking_id has been compiled and injected successfully.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error_message = "Direct execution error: " . $e->getMessage();
            }
        }
    }

    if ($action === 'override_fnb_payment_method') {
        $order_id     = (int)$_POST['fnb_order_id'];
        $method_type  = $_POST['fnb_method_type'] ?? '';
        $cash_amt     = (float)($_POST['fnb_cash_amount'] ?? 0);
        $card_amt     = (float)($_POST['fnb_card_amount'] ?? 0);
        $reason       = trim($_POST['fnb_reason'] ?? '');
        $admin_name   = $_SESSION['admin_fullname'] ?? $_SESSION['admin_username'] ?? 'Super GM';
        if ($order_id <= 0 || empty($method_type) || empty($reason)) {
            $error_message = "F&B Order ID, new payment method, and reason are all mandatory.";
        } else {
            $final_method = '';
            switch ($method_type) {
                case 'cash':  $final_method = 'Cash'; break;
                case 'card':  $final_method = 'Card'; break;
                case 'qr_points': $final_method = 'qr_points'; break;
                case 'mixed':
                    if ($cash_amt <= 0 || $card_amt <= 0) {
                        $error_message = "Mixed payment requires BOTH cash and card amounts (greater than 0).";
                    } else {
                        $final_method = "Cash: " . number_format($cash_amt, 2) . " | Card: " . number_format($card_amt, 2);
                    }
                    break;
                default: $error_message = "Invalid payment method type selected.";
            }
            if (empty($error_message) && !empty($final_method)) {
                try {
                    $stmtOld = $pdo->prepare("SELECT payment_method FROM fnb_orders WHERE id = ?");
                    $stmtOld->execute([$order_id]);
                    $old_method = $stmtOld->fetchColumn();
                    if ($old_method === false) {
                        $error_message = "F&B Order #$order_id not found.";
                    } else {
                        $pdo->beginTransaction();
                        $stmtUpd = $pdo->prepare("UPDATE fnb_orders SET payment_method = ? WHERE id = ?");
                        $stmtUpd->execute([$final_method, $order_id]);
                        try {
                            $note = "PAYMENT METHOD OVERRIDE | OLD: [$old_method] -> NEW: [$final_method] | Reason: $reason";
                            $stmtLog = $pdo->prepare("INSERT INTO fnb_action_log (order_id, action_type, performed_by, shop_name, notes, created_at) VALUES (?, 'REPRINT', ?, 'Super GM Desk', ?, NOW())");
                            $stmtLog->execute([$order_id, $admin_name, $note]);
                        } catch (Exception $logErr) { /* silent */ }
                        $pdo->commit();
                        $message = "Success! F&B Order #$order_id payment method changed from [$old_method] to [$final_method].";
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    $error_message = "F&B Override failed: " . $e->getMessage();
                }
            }
        }
    }


    if ($action === 'delete_fnb_order') {
        $order_id      = (int)$_POST['del_fnb_order_id'];
        $reason        = trim($_POST['del_reason'] ?? '');
        $admin_name    = $_SESSION['admin_fullname'] ?? $_SESSION['admin_username'] ?? 'Super GM';
        if ($order_id <= 0) {
            $error_message = "Invalid F&B Order ID.";
        } elseif (empty($reason)) {
            $error_message = "Deletion reason is required for audit trail.";
        } else {
            try {
                $stmtSnap = $pdo->prepare("SELECT * FROM fnb_orders WHERE id = ?");
                $stmtSnap->execute([$order_id]);
                $snapshot = $stmtSnap->fetch(PDO::FETCH_ASSOC);
                if (!$snapshot) {
                    $error_message = "F&B Order #$order_id not found or already deleted.";
                } else {
                    $stmtSnapI = $pdo->prepare("SELECT product_name, quantity, size, price FROM fnb_order_items WHERE order_id = ?");
                    $stmtSnapI->execute([$order_id]);
                    $items_snap = $stmtSnapI->fetchAll(PDO::FETCH_ASSOC);
                    $items_summary = [];
                    foreach ($items_snap as $it) {
                        $items_summary[] = "{$it['quantity']}x {$it['product_name']} @ {$it['price']}";
                    }
                    $items_str = implode(' | ', $items_summary);
                    $pdo->beginTransaction();
                    $stmt1 = $pdo->prepare("DELETE FROM fnb_order_items WHERE order_id = ?");
                    $stmt1->execute([$order_id]);
                    $items_deleted = $stmt1->rowCount();
                    $stmt2 = $pdo->prepare("DELETE FROM fnb_action_log WHERE order_id = ?");
                    $stmt2->execute([$order_id]);
                    $logs_deleted = $stmt2->rowCount();
                    $stmt3 = $pdo->prepare("DELETE FROM fnb_orders WHERE id = ?");
                    $stmt3->execute([$order_id]);
                    try {
                        $audit = "FNB ORDER DELETED | OrderID: $order_id | Customer: {$snapshot['customer_name']} | Total: {$snapshot['total_amount']} | Status: {$snapshot['payment_status']} | Items: $items_str | ItemsDeleted: $items_deleted | LogsDeleted: $logs_deleted | Reason: $reason | By: $admin_name";
                        $stmtAud = $pdo->prepare("INSERT INTO admin_audit (admin_name, action_type, target_id, details, created_at) VALUES (?, 'fnb_order_deleted', ?, ?, NOW())");
                        $stmtAud->execute([$admin_name, $order_id, $audit]);
                    } catch (Exception $auditErr) { /* silent */ }
                    $pdo->commit();
                    $message = "F&B Order #$order_id permanently deleted. ($items_deleted item(s), $logs_deleted action log(s) cleaned). Customer: {$snapshot['customer_name']}.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error_message = "Deletion failed: " . $e->getMessage();
            }
        }
    }

// =========================================================
    // [NEW] MAIN BOOKING DELETE (Deduct amounts)
    // =========================================================
    if ($action === 'delete_booking') {
        $booking_id = (int)$_POST['del_booking_id'];
        $reason     = trim($_POST['del_booking_reason'] ?? '');
        $admin_name = $_SESSION['admin_fullname'] ?? $_SESSION['admin_username'] ?? 'Super GM';

        if ($booking_id <= 0) {
            $error_message = "Invalid Booking ID.";
        } elseif (empty($reason)) {
            $error_message = "Deletion reason is required for audit trail.";
        } else {
            try {
                // 1. Fetch snapshot for audit log before deleting
                $stmtSnap = $pdo->prepare("SELECT * FROM bookings WHERE booking_id = ?");
                $stmtSnap->execute([$booking_id]);
                $snapshot = $stmtSnap->fetch(PDO::FETCH_ASSOC);

                if (!$snapshot) {
                    $error_message = "Booking #$booking_id not found or already deleted.";
                } else {
                    $pdo->beginTransaction();

                    // 2. Cascade delete related items and addon records to ensure totals drop
                    $stmtItems = $pdo->prepare("DELETE FROM booking_items WHERE booking_id = ?");
                    $stmtItems->execute([$booking_id]);
                    $items_deleted = $stmtItems->rowCount();

                    $stmtAddons = $pdo->prepare("DELETE FROM addon_redemptions WHERE booking_id = ?");
                    $stmtAddons->execute([$booking_id]);

                    // Try removing from purchase logs just in case
                    try {
                        $stmtLogs = $pdo->prepare("DELETE FROM addon_purchase_logs WHERE booking_id = ?");
                        $stmtLogs->execute([$booking_id]);
                    } catch (Exception $e) {}

                    // 3. Delete the main booking
                    $stmtBooking = $pdo->prepare("DELETE FROM bookings WHERE booking_id = ?");
                    $stmtBooking->execute([$booking_id]);

                    // 4. Record to Audit Trail
                    try {
                        $audit = "MAIN BOOKING DELETED | BookingID: $booking_id | Customer: {$snapshot['customer_name']} | Amount Deducted: AED {$snapshot['total_amount']} | Items Cleared: $items_deleted | Reason: $reason | By: $admin_name";
                        $stmtAud = $pdo->prepare("INSERT INTO admin_audit (admin_name, action_type, target_id, details, created_at) VALUES (?, 'booking_deleted', ?, ?, NOW())");
                        $stmtAud->execute([$admin_name, $booking_id, $audit]);
                    } catch (Exception $auditErr) { /* silent if audit table missing */ }

                    $pdo->commit();
                    $message = "Success! Booking #$booking_id has been permanently deleted. AED {$snapshot['total_amount']} has been removed from system totals.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error_message = "Booking Deletion failed: " . $e->getMessage();
            }
        }
    }
    // =========================================================
    // [NEW] FREE ADDON INJECTION (Complimentary, No Charge)
    // =========================================================
    if ($action === 'add_free_addon') {
        $booking_id   = (int)$_POST['fa_booking_id'];
        $product_id   = trim($_POST['fa_product_id'] ?? '');
        $qty          = (int)($_POST['fa_quantity'] ?? 0);
        $reason       = trim($_POST['fa_reason'] ?? '');
        $admin_name   = $_SESSION['admin_fullname'] ?? $_SESSION['admin_username'] ?? 'Super GM';

        if ($booking_id <= 0) {
            $error_message = "Invalid Booking ID.";
        } elseif (empty($product_id)) {
            $error_message = "Please select an addon product.";
        } elseif ($qty <= 0 || $qty > 50) {
            $error_message = "Quantity must be between 1 and 50.";
        } elseif (empty($reason)) {
            $error_message = "Reason is mandatory for audit trail (e.g. 'Kiosk error', 'Customer compensation').";
        } else {
            try {
                // 1. Verify booking exists
                $stmtBk = $pdo->prepare("SELECT booking_id, customer_name FROM bookings WHERE booking_id = ? LIMIT 1");
                $stmtBk->execute([$booking_id]);
                $booking_row = $stmtBk->fetch(PDO::FETCH_ASSOC);
                if (!$booking_row) {
                    $error_message = "Booking #$booking_id not found.";
                } else {
                    // 2. Verify product exists and belongs to addon category
                    $stmtP = $pdo->prepare("SELECT product_id, name, price FROM products WHERE product_id = ? AND category_id = 6 LIMIT 1");
                    $stmtP->execute([$product_id]);
                    $prod = $stmtP->fetch(PDO::FETCH_ASSOC);
                    if (!$prod) {
                        $error_message = "Selected product is not a valid addon.";
                    } else {
                        $pdo->beginTransaction();

                        // 3. Check if redemption record already exists for this booking + product
                        $stmtCheck = $pdo->prepare("SELECT id, quantity_total, quantity_used FROM addon_redemptions WHERE booking_id = ? AND product_id = ? LIMIT 1");
                        $stmtCheck->execute([$booking_id, $product_id]);
                        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                        $action_msg = '';
                        if ($existing) {
                            // UPDATE: top-up the quantity_total (also reset status to 'unused' if it was fully used)
                            $new_total = (int)$existing['quantity_total'] + $qty;
                            $new_status = ($existing['quantity_used'] >= $new_total) ? 'used' : 'unused';
                            $stmtU = $pdo->prepare("UPDATE addon_redemptions SET quantity_total = ?, status = ? WHERE id = ?");
                            $stmtU->execute([$new_total, $new_status, $existing['id']]);
                            $action_msg = "TOPPED UP existing redemption (was {$existing['quantity_total']}, now $new_total)";
                        } else {
                            // INSERT new redemption record
                            $unique_code = $booking_id . '-' . $product_id;
                            $stmtI = $pdo->prepare("INSERT INTO addon_redemptions (booking_id, product_id, unique_code, status, quantity_total, quantity_used) VALUES (?, ?, ?, 'unused', ?, 0)");
                            $stmtI->execute([$booking_id, $product_id, $unique_code, $qty]);
                            $action_msg = "CREATED new redemption with code $unique_code";
                        }

                        // 4. Log into addon_purchase_logs as COMPLIMENTARY (no charge)
                        $items_summary = "FREE ADDON | {$qty}x {$prod['name']} ({$product_id}) | Reason: $reason | By: $admin_name | Action: $action_msg";
                        try {
                            $stmtLog = $pdo->prepare("INSERT INTO addon_purchase_logs (booking_id, action, payment_status, payment_method, total_amount, vat_amount, items_summary, created_by, created_at) VALUES (?, 'COMPLIMENTARY', 'paid', 'complimentary', 0.00, 0.00, ?, ?, NOW())");
                            $stmtLog->execute([$booking_id, $items_summary, $admin_name]);
                        } catch (Exception $logErr) { /* silent if columns differ */ }

                        // 5. Optional admin_audit insert
                        try {
                            $audit = "FREE ADDON INJECTED | Booking #$booking_id ({$booking_row['customer_name']}) | {$qty}x {$prod['name']} ($product_id) | Reason: $reason | By: $admin_name | $action_msg";
                            $stmtAud = $pdo->prepare("INSERT INTO admin_audit (admin_name, action_type, target_id, details, created_at) VALUES (?, 'free_addon_injected', ?, ?, NOW())");
                            $stmtAud->execute([$admin_name, $booking_id, $audit]);
                        } catch (Exception $auditErr) { /* silent if table missing */ }

                        $pdo->commit();
                        $message = "Success! {$qty}x {$prod['name']} added FREE to Booking #$booking_id ({$booking_row['customer_name']}). $action_msg.";
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error_message = "Free addon injection failed: " . $e->getMessage();
            }
        }
    }
}



// ==========================================
// RENDER COMPONENT SCHEMAS
// ==========================================
try {
    $stmt_pendings = $pdo->query("SELECT booking_id, customer_name, total_amount, visit_date, created_at FROM bookings WHERE payment_status = 'pending' ORDER BY created_at DESC LIMIT 50");
    $pending_bookings = $stmt_pendings->fetchAll(PDO::FETCH_ASSOC);

    $stmt_tickets = $pdo->query("SELECT tt.type_id, tt.category, tt.sub_label, tt.price, p.name as package_name FROM ticket_types tt LEFT JOIN products p ON tt.product_id = p.product_id ORDER BY p.name ASC, tt.price DESC");
    $ticket_types = $stmt_tickets->fetchAll(PDO::FETCH_ASSOC);

    $stmt_addons = $pdo->query("SELECT product_id, name, price FROM products WHERE product_id NOT IN (SELECT DISTINCT product_id FROM ticket_types WHERE product_id IS NOT NULL)");
    $products = $stmt_addons->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("System Schema Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super GM Desk - Real-time Gateway Audit Engine</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #eef2f5; margin: 0; padding: 25px; color: #334155; }
        .main-grid { max-width: 1420px; margin: 0 auto; display: grid; grid-template-columns: 480px 1fr; gap: 25px; }
        .panel-card { background: white; border-radius: 14px; padding: 30px; box-shadow: 0 10px 25px rgba(0,0,0,0.04); border-top: 5px solid #003B72; box-sizing: border-box; }
        .panel-card.pos-mode { border-top-color: #ef4444; }
        h1 { color: #003B72; font-size: 22px; margin: 0 0 5px 0; display: flex; align-items: center; gap: 10px; font-weight: 700; }
        .desc { color: #64748b; font-size: 13px; margin-bottom: 25px; line-height: 1.4; }
        .scroller-zone { max-height: 680px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 10px; background: #fafafa; }
        .live-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .live-table th { background: #f1f5f9; padding: 14px 10px; border-bottom: 2px solid #e2e8f0; color: #475569; position: sticky; top: 0; z-index: 10; }
        .live-table td { padding: 14px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .row-item { cursor: pointer; transition: 0.2s; }
        .row-item:hover { background: #e0f2fe; transform: scale(0.99); }
        .badge-amber { background: #fef3c7; color: #d97706; padding: 4px 10px; border-radius: 50px; font-weight: bold; font-size: 11px; text-transform: uppercase; }
        .badge-slate { background: #e2e8f0; color: #334155; padding: 3px 6px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .form-layout { margin-bottom: 18px; }
        label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 13px; color: #475569; }
        input[type="text"], input[type="number"], input[type="date"], input[type="email"], select { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box; font-size: 14px; background: #f8fafc; }
        .switcher-header { display: flex; gap: 12px; margin-bottom: 25px; border-bottom: 2px solid #e2e8f0; padding-bottom: 12px; flex-wrap: wrap; }
        .tab-trigger { padding: 12px 18px; border: none; background: #e2e8f0; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; color: #475569; display: flex; align-items: center; gap: 6px; }
        .tab-trigger.active { background: #003B72; color: white; }
        .decoded-cart-box { background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 15px; margin-bottom: 15px; display: none; }
        .bank-status-banner { padding: 15px; border-radius: 8px; font-weight: bold; font-size: 14px; margin-bottom: 15px; display: none; align-items: center; justify-content: space-between; }
        .bank-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .bank-pending { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .banner-pricing { background: #003B72; color: white; padding: 22px; border-radius: 8px; text-align: right; margin-top: 25px; }
        .banner-pricing h2 { margin: 0; font-size: 28px; }
        .btn-execute { background: #10b981; color: white; border: none; padding: 15px; font-weight: bold; border-radius: 8px; cursor: pointer; width: 100%; text-transform: uppercase; margin-top: 15px; font-size: 15px; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-danger { background: #ef4444; }
        .global-alert { padding: 15px; border-radius: 8px; margin-bottom: 25px; font-size: 14px; font-weight: 600; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .time-label { font-size: 11px; color: #0284c7; background: #e0f2fe; padding: 2px 6px; border-radius: 4px; font-weight: 600; display: inline-block; margin-top: 4px; }
        .btn-autofill { background: #16a34a; color: white; padding: 6px 12px; font-size: 11px; font-weight: bold; border-radius: 4px; text-decoration: none; border: none; cursor: pointer; }
    </style>
</head>
<body>



<div style="max-width: 1400px; margin: 0 auto 15px auto;">
    <?php if (!empty($message)): ?>
        <div class="global-alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="global-alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo $error_message; ?></div>
    <?php endif; ?>
</div>

<div class="main-grid">
    <div class="panel-card">
        <h1><i class="fas fa-history" style="color:#d97706;"></i> Live Checkout Checkpoints</h1>
        <div class="desc">Select any pending checkout line to auto-read item descriptors and instantly run a security scan across the live merchant payment network.</div>
        <div class="scroller-zone">
            <table class="live-table">
                <thead>
                    <tr><th>ID</th><th>Guest Profiles & Timestamps</th><th style="text-align:right;">Charge</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php if (count($pending_bookings) === 0): ?>
                        <tr><td colspan="4" style="text-align:center; color:#94a3b8; padding:40px;">No pending checkpoint sessions found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($pending_bookings as $p_book): ?>
                            <tr class="row-item" onclick="fetchLiveBookingBreakdown(<?php echo $p_book['booking_id'] ?? 0; ?>, '<?php echo htmlspecialchars($p_book['customer_name'] ?? '', ENT_QUOTES); ?>', <?php echo $p_book['total_amount'] ?? 0; ?>)">
                                <td><strong>#<?php echo $p_book['booking_id'] ?? 0; ?></strong></td>
                                <td>
                                    <span style="font-weight:700; color:#1e293b;"><?php echo htmlspecialchars($p_book['customer_name'] ?? ''); ?></span><br>
                                    <span style="font-size:11px; color:#64748b;"><i class="far fa-calendar-alt"></i> Plan: <?php echo date('M d, Y', strtotime($p_book['visit_date'] ?? '')); ?></span><br>
                                    <span class="time-label"><i class="far fa-clock"></i> Attempted: <?php echo date('h:i A', strtotime($p_book['created_at'] ?? '')); ?></span>
                                </td>
                                <td style="text-align:right; font-weight:800; color:#003B72;">AED <?php echo number_format($p_book['total_amount'] ?? 0, 2); ?></td>
                                <td><span class="badge-amber">Pending</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel-card pos-mode">
        <div class="switcher-header">
            <button class="tab-trigger active" onclick="toggleActiveTab('retrieve-tab', this)"><i class="fas fa-folder-open"></i> Option 1: Live Bank Audit</button>
            <button class="tab-trigger" onclick="toggleActiveTab('pos-tab', this)"><i class="fas fa-cash-register"></i> Option 2: Manual POS Entry</button>
            <button class="tab-trigger" onclick="toggleActiveTab('payment-override-tab', this)"><i class="fas fa-exchange-alt"></i> Option 3: Booking Payment Correction</button>
            <button class="tab-trigger" onclick="toggleActiveTab('fnb-payment-tab', this); loadFnbList();" style="background:#fef3c7; color:#92400e;"><i class="fas fa-utensils"></i> Option 4: F&B Payment Correction</button>
            <button class="tab-trigger" onclick="toggleActiveTab('fnb-delete-tab', this); loadFnbDeleteList();" style="background:#fee2e2; color:#991b1b;"><i class="fas fa-trash-alt"></i> Option 5: F&B Test Order Delete</button>
            <button class="tab-trigger" onclick="toggleActiveTab('free-addon-tab', this); loadAddonOptions();" style="background:#dcfce7; color:#166534;"><i class="fas fa-gift"></i> Option 6: Free Addon (No Charge)</button>
            <button class="tab-trigger" onclick="toggleActiveTab('delete-booking-tab', this);" style="background:#fee2e2; color:#b91c1c;"><i class="fas fa-calendar-times"></i> Option 7: AWP Booking System Items Override</button>
        </div>



        <div id="retrieve-tab" class="tab-content">
            <h1><i class="fas fa-search-dollar"></i> Live Gateway Network Verification</h1>
            <div class="sub">Click a checkpoint on the left. The system will inspect database parameters and run a direct server-to-server check to confirm if money left the card.</div>
            <div id="bank_status_banner" class="bank-status-banner"></div>
            <div id="item_decoder_board" class="decoded-cart-box" style="background:#f8fafc; border: 1px solid #e2e8f0;">
                <h3 style="margin:0 0 10px 0; font-size:13px; color:#334155;"><i class="fas fa-box-open"></i> Checkout Inventory Selection Component Checklist:</h3>
                <table style="width:100%; font-size:13px; text-align:left; border-collapse:collapse;" id="decoded_items_table"></table>
            </div>
            <form action="super_gm_override.php" method="POST">
                <input type="hidden" name="action" value="force_override_existing">
                <div class="form-layout">
                    <label>Selected Target Booking Reference ID:</label>
                    <input type="number" id="target_booking_id" name="booking_id" readonly style="background:#e2e8f0; font-weight:700; color:#003B72;">
                </div>
                <div class="form-layout">
                    <label>Customer Registered Name Check:</label>
                    <input type="text" id="target_customer_name" disabled placeholder="No entry active">
                </div>
                <div class="form-layout">
                    <label>N-Genius Gateway Reference Code Number <span style="color:red;">*</span></label>
                    <input type="text" id="target_manual_reference" name="manual_reference" placeholder="Enter Reference Code or use Auto-Scan feature above" required>
                </div>
                <button type="submit" class="btn-execute btn-danger"><i class="fas fa-check-double"></i> Authorize & Push Status to Dashboard (PAID)</button>
            </form>
        </div>

        <div id="pos-tab" class="tab-content" style="display:none;">
            <h1><i class="fas fa-calculator"></i> Custom Structural Vouchers Entry POS</h1>
            <div class="sub">Fallback override option if no record data exists in the checkpoint register panel but money was charged.</div>
            <form action="super_gm_override.php" method="POST">
                <input type="hidden" name="action" value="execute_manual_pos">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div class="form-layout"><label>Guest Full Name <span style="color:red;">*</span></label><input type="text" name="customer_name" placeholder="John Doe"></div>
                    <div class="form-layout"><label>Email Address Notification Link:</label><input type="email" name="customer_email" placeholder="email@address.com"></div>
                    <div class="form-layout"><label>Phone Mobile Contact Number:</label><input type="text" name="customer_phone" placeholder="+971 50 000 0000"></div>
                    <div class="form-layout"><label>Target Entry Date <span style="color:red;">*</span></label><input type="date" name="visit_date" value="<?php echo date('Y-m-d'); ?>"></div>
                    <div class="form-layout" style="grid-column: span 2;"><label>N-Genius Gateway Document Reference <span style="color:red;">*</span></label><input type="text" name="ngenius_reference" placeholder="Paste verification reference id sequence"></div>
                </div>
                <label style="margin-top:15px; display:block; font-weight:700;">Select Explicit Tickets Inventory Quantities:</label>
                <div style="max-height: 250px; overflow-y:auto; border:1px solid #e2e8f0; padding:10px; border-radius:8px; background:#fafafa; margin-bottom:15px;">
                    <table class="live-table">
                        <thead><tr><th>Operational Description</th><th style="text-align:right;">Price Rate</th><th style="text-align:center; width:90px;">Quantity</th></tr></thead>
                        <tbody>
                            <?php foreach ($ticket_types as $ticket): ?>
                                <?php
                                    $item_id = 'type_' . $ticket['type_id'];
                                    $lbl = (!empty($ticket['package_name']) ? $ticket['package_name'] . ' - ' : '') . $ticket['category'];
                                    if(!empty($ticket['sub_label'])) $lbl .= " (" . $ticket['sub_label'] . ")";
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($lbl); ?></strong><br><span class="badge-slate">Entry Voucher</span></td>
                                    <td style="text-align:right; font-weight:600;">AED <?php echo number_format($ticket['price'] ?? 0, 2); ?></td>
                                    <td style="text-align:center;"><input type="number" name="qty[<?php echo $item_id; ?>]" class="qty-calc live-math-engine" min="0" value="0" data-price="<?php echo $ticket['price'] ?? 0; ?>" style="width:65px; text-align:center; padding:5px; border-radius:4px; border:1px solid #cbd5e1;"></td>
                                    <input type="hidden" name="price[<?php echo $item_id; ?>]" value="<?php echo $ticket['price'] ?? 0; ?>">
                                </tr>
                            <?php endforeach; ?>
                            <?php foreach ($products as $prod): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($prod['name'] ?? ''); ?></strong><br><span class="badge-slate" style="background:#e0f2fe; color:#0369a1;">Add-on Accessory</span></td>
                                    <td style="text-align:right; font-weight:600;">AED <?php echo number_format($prod['price'] ?? 0, 2); ?></td>
                                    <td style="text-align:center;"><input type="number" name="qty[<?php echo $prod['product_id'] ?? '' ; ?>]" class="qty-calc live-math-engine" min="0" value="0" data-price="<?php echo $prod['price'] ?? 0; ?>" style="width:65px; text-align:center; padding:5px; border-radius:4px; border:1px solid #cbd5e1;"></td>
                                    <input type="hidden" name="price[<?php echo $prod['product_id'] ?? '' ; ?>]" value="<?php echo $prod['price'] ?? 0; ?>">
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="banner-pricing">
                    <span style="font-size:11px; font-weight:700; opacity:0.85;">CALCULATED REGISTER TOTAL:</span>
                    <h2 id="pos_total_display">AED 0.00</h2>
                    <input type="hidden" id="hidden_total_amount" name="hidden_total_amount" value="0.00">
                </div>
                <button type="submit" class="btn-execute"><i class="fas fa-save"></i> Inject Structural Records Matrix</button>
            </form>
        </div>



        <!-- OPTION 3: PAYMENT METHOD CORRECTION -->
        <div id="payment-override-tab" class="tab-content" style="display:none;">
            <h1><i class="fas fa-exchange-alt" style="color:#8b5cf6;"></i> Payment Method Correction Override</h1>
            <div class="sub" style="color:#64748b; font-size:13px; margin-bottom:20px; line-height:1.5;">
                Use this when reception accidentally recorded the wrong payment method (e.g., customer paid CASH but it was logged as CARD). This will only update the <code>payment_method</code> column without affecting the booking status or amounts.
            </div>
            <div class="form-layout">
                <label>Booking Reference ID <span style="color:red;">*</span></label>
                <div style="display:flex; gap:10px;">
                    <input type="number" id="ovr_booking_lookup" placeholder="Enter Booking ID (e.g. 123)" style="flex:1;">
                    <button type="button" onclick="loadPaymentInfo()" style="background:#003B72; color:white; border:none; padding:10px 20px; border-radius:8px; font-weight:bold; cursor:pointer; white-space:nowrap;"><i class="fas fa-search"></i> Load Booking</button>
                </div>
            </div>
            <div id="ovr_booking_info" style="display:none; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:15px; margin-bottom:15px;">
                <h3 style="margin:0 0 10px 0; font-size:14px; color:#334155;"><i class="fas fa-info-circle"></i> Current Booking Details</h3>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; font-size:13px;">
                    <div><strong>Customer:</strong> <span id="ovr_info_name">-</span></div>
                    <div><strong>Email:</strong> <span id="ovr_info_email">-</span></div>
                    <div><strong>Total:</strong> AED <span id="ovr_info_total">-</span></div>
                    <div><strong>Visit Date:</strong> <span id="ovr_info_visit">-</span></div>
                    <div><strong>Status:</strong> <span id="ovr_info_status" style="font-weight:bold; color:#16a34a;">-</span></div>
                    <div><strong>Current Method:</strong> <span id="ovr_info_method" style="font-weight:bold; color:#dc2626;">-</span></div>
                </div>
            </div>
            <form action="super_gm_override.php" method="POST" id="paymentOverrideForm" style="display:none;">
                <input type="hidden" name="action" value="override_payment_method">
                <input type="hidden" name="ovr_booking_id" id="ovr_booking_id">
                <div class="form-layout">
                    <label>New Payment Method <span style="color:red;">*</span></label>
                    <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:10px;">
                        <label style="display:flex; align-items:center; gap:8px; padding:12px; background:#ecfdf5; border:2px solid #d1fae5; border-radius:8px; cursor:pointer; font-weight:bold; color:#065f46;"><input type="radio" name="ovr_method_type" value="cash" onchange="toggleMixedFields(false)" required><i class="fas fa-money-bill-wave"></i> Cash</label>
                        <label style="display:flex; align-items:center; gap:8px; padding:12px; background:#fff7ed; border:2px solid #fed7aa; border-radius:8px; cursor:pointer; font-weight:bold; color:#9a3412;"><input type="radio" name="ovr_method_type" value="card" onchange="toggleMixedFields(false)"><i class="fas fa-credit-card"></i> Card</label>
                        <label style="display:flex; align-items:center; gap:8px; padding:12px; background:#eff6ff; border:2px solid #bfdbfe; border-radius:8px; cursor:pointer; font-weight:bold; color:#1e3a8a;"><input type="radio" name="ovr_method_type" value="qr_points" onchange="toggleMixedFields(false)"><i class="fas fa-wallet"></i> QR Wallet</label>
                        <label style="display:flex; align-items:center; gap:8px; padding:12px; background:#faf5ff; border:2px solid #e9d5ff; border-radius:8px; cursor:pointer; font-weight:bold; color:#6b21a8;"><input type="radio" name="ovr_method_type" value="mixed" onchange="toggleMixedFields(true)"><i class="fas fa-calculator"></i> Mixed (Cash + Card)</label>
                    </div>
                </div>
                <div id="ovr_mixed_fields" style="display:none; background:#faf5ff; border:1px dashed #c084fc; border-radius:10px; padding:15px; margin-bottom:15px;">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                        <div class="form-layout" style="margin:0;"><label><i class="fas fa-money-bill-wave" style="color:#16a34a;"></i> Cash Amount (AED)</label><input type="number" name="ovr_cash_amount" id="ovr_cash_amount" step="0.01" min="0" placeholder="0.00" oninput="validateMixedTotal()"></div>
                        <div class="form-layout" style="margin:0;"><label><i class="fas fa-credit-card" style="color:#f59e0b;"></i> Card Amount (AED)</label><input type="number" name="ovr_card_amount" id="ovr_card_amount" step="0.01" min="0" placeholder="0.00" oninput="validateMixedTotal()"></div>
                    </div>
                    <div id="ovr_mixed_warning" style="margin-top:10px; padding:8px; background:#fff; border-radius:6px; font-size:12px; color:#64748b; text-align:center;">Cash + Card must equal AED <strong id="ovr_expected_total">0.00</strong></div>
                </div>
                <div class="form-layout"><label>Reason for Override <span style="color:red;">*</span></label><input type="text" name="ovr_reason" placeholder="e.g. Reception clicked Card by mistake, customer actually paid Cash" required></div>
                <button type="submit" class="btn-execute" style="background:#8b5cf6;" onclick="return confirm('Are you sure you want to OVERRIDE the payment method for this booking? This will be logged in admin audit.');"><i class="fas fa-exchange-alt"></i> Apply Payment Method Correction</button>
            </form>
        </div>



        <!-- OPTION 4: F&B PAYMENT METHOD CORRECTION -->
        <div id="fnb-payment-tab" class="tab-content" style="display:none;">
            <h1><i class="fas fa-utensils" style="color:#d97706;"></i> F&B Order — Payment Method Correction</h1>
            <div class="sub" style="color:#64748b; font-size:13px; margin-bottom:20px; line-height:1.5;">
                Use this kapag mali ang na-select na payment method ng cashier sa F&B (e.g., customer paid CASH but logged as CARD). This only updates the <code>payment_method</code> column. Walang ibang masisira sa system.
            </div>
            <div class="form-layout">
                <label>F&B Order ID <span style="color:red;">*</span></label>
                <div style="display:flex; gap:10px;">
                    <input type="number" id="fnb_order_lookup" placeholder="Enter F&B Order ID (e.g. 5)" style="flex:1;">
                    <button type="button" onclick="loadFnbInfo('payment')" style="background:#003B72; color:white; border:none; padding:10px 20px; border-radius:8px; font-weight:bold; cursor:pointer; white-space:nowrap;"><i class="fas fa-search"></i> Load Order</button>
                </div>
            </div>
            <div style="background:#fffbeb; border:1px dashed #fbbf24; border-radius:10px; padding:12px; margin-bottom:15px; max-height:220px; overflow-y:auto;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <strong style="font-size:12px; color:#92400e;"><i class="fas fa-list"></i> Recent F&B Orders (click to load)</strong>
                    <select id="fnb_filter" onchange="loadFnbList()" style="font-size:11px; padding:4px 8px; border-radius:4px; border:1px solid #fbbf24;">
                        <option value="all">All</option><option value="unpaid">Unpaid Only</option><option value="today">Today Only</option>
                    </select>
                </div>
                <div id="fnb_quick_list" style="font-size:12px;"></div>
            </div>
            <div id="fnb_pay_info" style="display:none; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:15px; margin-bottom:15px;">
                <h3 style="margin:0 0 10px 0; font-size:14px; color:#334155;"><i class="fas fa-info-circle"></i> Current F&B Order Details</h3>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; font-size:13px;">
                    <div><strong>Customer:</strong> <span id="fnb_pay_name">-</span></div>
                    <div><strong>Phone:</strong> <span id="fnb_pay_phone">-</span></div>
                    <div><strong>Total:</strong> AED <span id="fnb_pay_total">-</span></div>
                    <div><strong>Shop:</strong> <span id="fnb_pay_shop">-</span></div>
                    <div><strong>Pay Status:</strong> <span id="fnb_pay_status" style="font-weight:bold;">-</span></div>
                    <div><strong>Current Method:</strong> <span id="fnb_pay_method" style="font-weight:bold; color:#dc2626;">-</span></div>
                </div>
                <div id="fnb_pay_items" style="margin-top:10px; font-size:12px; color:#64748b;"></div>
            </div>
            <form action="super_gm_override.php" method="POST" id="fnbPaymentForm" style="display:none;">
                <input type="hidden" name="action" value="override_fnb_payment_method">
                <input type="hidden" name="fnb_order_id" id="fnb_order_id">
                <div class="form-layout">
                    <label>New Payment Method <span style="color:red;">*</span></label>
                    <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:10px;">
                        <label style="display:flex; align-items:center; gap:8px; padding:12px; background:#ecfdf5; border:2px solid #d1fae5; border-radius:8px; cursor:pointer; font-weight:bold; color:#065f46;"><input type="radio" name="fnb_method_type" value="cash" onchange="toggleFnbMixed(false)" required><i class="fas fa-money-bill-wave"></i> Cash</label>
                        <label style="display:flex; align-items:center; gap:8px; padding:12px; background:#fff7ed; border:2px solid #fed7aa; border-radius:8px; cursor:pointer; font-weight:bold; color:#9a3412;"><input type="radio" name="fnb_method_type" value="card" onchange="toggleFnbMixed(false)"><i class="fas fa-credit-card"></i> Card</label>
                        <label style="display:flex; align-items:center; gap:8px; padding:12px; background:#eff6ff; border:2px solid #bfdbfe; border-radius:8px; cursor:pointer; font-weight:bold; color:#1e3a8a;"><input type="radio" name="fnb_method_type" value="qr_points" onchange="toggleFnbMixed(false)"><i class="fas fa-wallet"></i> QR Wallet</label>
                        <label style="display:flex; align-items:center; gap:8px; padding:12px; background:#faf5ff; border:2px solid #e9d5ff; border-radius:8px; cursor:pointer; font-weight:bold; color:#6b21a8;"><input type="radio" name="fnb_method_type" value="mixed" onchange="toggleFnbMixed(true)"><i class="fas fa-calculator"></i> Mixed</label>
                    </div>
                </div>
                <div id="fnb_mixed_fields" style="display:none; background:#faf5ff; border:1px dashed #c084fc; border-radius:10px; padding:15px; margin-bottom:15px;">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                        <div class="form-layout" style="margin:0;"><label><i class="fas fa-money-bill-wave" style="color:#16a34a;"></i> Cash Amount (AED)</label><input type="number" name="fnb_cash_amount" id="fnb_cash_amount" step="0.01" min="0" placeholder="0.00" oninput="validateFnbMixed()"></div>
                        <div class="form-layout" style="margin:0;"><label><i class="fas fa-credit-card" style="color:#f59e0b;"></i> Card Amount (AED)</label><input type="number" name="fnb_card_amount" id="fnb_card_amount" step="0.01" min="0" placeholder="0.00" oninput="validateFnbMixed()"></div>
                    </div>
                    <div id="fnb_mixed_warn" style="margin-top:10px; padding:8px; background:#fff; border-radius:6px; font-size:12px; color:#64748b; text-align:center;">Cash + Card must equal AED <strong id="fnb_expected_total">0.00</strong></div>
                </div>
                <div class="form-layout"><label>Reason for Override <span style="color:red;">*</span></label><input type="text" name="fnb_reason" placeholder="e.g. Cashier clicked Card by mistake, customer actually paid Cash" required></div>
                <button type="submit" class="btn-execute" style="background:#d97706;" onclick="return confirm('Apply payment method correction sa F&B order na ito? Maglo-log ito sa fnb_action_log.');"><i class="fas fa-exchange-alt"></i> Apply F&B Payment Correction</button>
            </form>
        </div>



      <!-- OPTION 5: F&B TEST ORDER BULK DELETE (checkbox based) -->
<div id="fnb-delete-tab" class="tab-content" style="display:none;">
    <h1><i class="fas fa-trash-alt" style="color:#dc2626;"></i> F&B Test Order — Bulk Safe Deletion</h1>
    <div class="sub" style="color:#64748b; font-size:13px; margin-bottom:15px; line-height:1.5;">
        Naka-load na lahat ng F&B orders sa baba — i-check mo lang yung gusto mong burahin (pwedeng maramihan), maglagay ng reason, tapos pindutin ang <strong>Delete Selected</strong>. <strong style="color:#dc2626;">Safe Cascade:</strong> bawat order may full audit snapshot sa <code>admin_audit</code> bago bumura. Hindi mag-rereload ang page kaya dito ka lang mananatili.
    </div>

    <div style="display:flex; gap:10px; margin-bottom:12px; flex-wrap:wrap;">
        <input type="text" id="fnb_del_search" onkeyup="filterFnbDeleteRows()" placeholder="Search by ID, customer, or shop...">
        <select id="fnb_del_filter" onchange="loadFnbDeleteList()" style="width:160px; padding:12px; border:1px solid #cbd5e1; border-radius:8px; background:#f8fafc;">
            <option value="all">All Orders</option>
            <option value="unpaid">Unpaid Only</option>
            <option value="today">Today Only</option>
        </select>
        <button type="button" onclick="loadFnbDeleteList()" style="background:#003B72; color:white; border:none; padding:10px 18px; border-radius:8px; font-weight:bold; cursor:pointer; white-space:nowrap;"><i class="fas fa-sync"></i> Refresh</button>
    </div>

    <div class="form-layout">
        <label>Deletion Reason (applies to all selected) <span style="color:red;">*</span></label>
        <input type="text" id="fnb_del_reason" placeholder="e.g. Test orders during system QA / wrong product clicked">
    </div>

    <div class="scroller-zone" style="max-height:420px; margin-bottom:15px;">
        <table class="live-table" id="fnb_del_table">
            <thead>
                <tr>
                    <th style="width:40px; text-align:center;"><input type="checkbox" id="fnb_del_check_all" onclick="toggleAllFnbDelete(this)"></th>
                    <th>ID</th><th>Customer</th><th>Shop</th><th style="text-align:right;">Total</th><th>Pay</th><th>Method</th><th>Created</th>
                </tr>
            </thead>
            <tbody id="fnb_del_tbody">
                <tr><td colspan="8" style="text-align:center; color:#94a3b8; padding:30px;"><i class="fas fa-spinner fa-spin"></i> Loading orders...</td></tr>
            </tbody>
        </table>
    </div>

    <button type="button" id="fnb_del_submit_btn" onclick="submitMultiDelete()" class="btn-execute btn-danger">
        <i class="fas fa-trash-alt"></i> Delete Selected (<span id="fnb_del_count">0</span>)
    </button>
</div>



        <!-- =========================================================
             [NEW] OPTION 6: FREE ADDON INJECTION (No Charge)
        ========================================================= -->
        <div id="free-addon-tab" class="tab-content" style="display:none;">
            <h1><i class="fas fa-gift" style="color:#16a34a;"></i> Free Addon Injection — Complimentary</h1>
            <div class="sub" style="color:#64748b; font-size:13px; margin-bottom:15px; line-height:1.5;">
                Use this para mag-add ng addon (Parking, Locker, Combo Meal, etc.) sa existing customer <strong style="color:#16a34a;">WITHOUT any charge</strong>. Helpful kapag may issue sa kiosk mode at need mo i-compensate yung customer. May audit trail at logging — walang masisira sa pricing/payment columns.
            </div>

            <div style="background:#ecfdf5; border:2px solid #bbf7d0; border-radius:10px; padding:12px; margin-bottom:15px; font-size:12px; color:#166534;">
                <strong><i class="fas fa-shield-alt"></i> Paano gumagana:</strong>
                <ul style="margin:6px 0 0 18px; padding:0;">
                    <li>Kung wala pang record yung booking para sa addon na yon → gagawa ng bagong row sa <code>addon_redemptions</code></li>
                    <li>Kung meron na (e.g. binili na niya dati ng 1 Locker) → dadagdag lang sa existing <code>quantity_total</code> (TOP-UP, walang duplicate)</li>
                    <li>Hindi maa-apekto yung <code>bookings.total_amount</code> — zero charge talaga</li>
                    <li>Magla-log sa <code>addon_purchase_logs</code> with action=<code>COMPLIMENTARY</code>, amount=0</li>
                    <li>Mandatory ang reason field para may audit trail</li>
                </ul>
            </div>

            <div class="form-layout">
                <label>Booking Reference ID <span style="color:red;">*</span></label>
                <div style="display:flex; gap:10px;">
                    <input type="number" id="fa_booking_lookup" placeholder="Enter Booking ID (e.g. 223)" style="flex:1;">
                    <button type="button" onclick="loadBookingForAddon()" style="background:#16a34a; color:white; border:none; padding:10px 20px; border-radius:8px; font-weight:bold; cursor:pointer; white-space:nowrap;">
                        <i class="fas fa-search"></i> Load Booking
                    </button>
                </div>
            </div>

            <!-- Booking Info Display -->
            <div id="fa_booking_info" style="display:none; background:#f0fdf4; border:1px solid #86efac; border-radius:10px; padding:15px; margin-bottom:15px;">
                <h3 style="margin:0 0 10px 0; font-size:14px; color:#166534;"><i class="fas fa-user-check"></i> Target Booking</h3>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; font-size:13px;">
                    <div><strong>Booking ID:</strong> #<span id="fa_info_id">-</span></div>
                    <div><strong>Customer:</strong> <span id="fa_info_name">-</span></div>
                    <div><strong>Email:</strong> <span id="fa_info_email">-</span></div>
                    <div><strong>Phone:</strong> <span id="fa_info_phone">-</span></div>
                    <div><strong>Visit Date:</strong> <span id="fa_info_visit">-</span></div>
                    <div><strong>Pay Status:</strong> <span id="fa_info_status" style="font-weight:bold;">-</span></div>
                </div>
                <div id="fa_existing_addons_box" style="margin-top:12px; padding:10px; background:#fff; border-radius:6px; font-size:12px; color:#475569; border:1px dashed #86efac;">
                    <strong style="color:#166534;"><i class="fas fa-box"></i> Existing addons na naka-load na:</strong>
                    <div id="fa_existing_addons_list" style="margin-top:6px;">-</div>
                </div>
            </div>

            <form action="super_gm_override.php" method="POST" id="freeAddonForm" style="display:none;">
                <input type="hidden" name="action" value="add_free_addon">
                <input type="hidden" name="fa_booking_id" id="fa_booking_id">

                <div style="display:grid; grid-template-columns: 2fr 1fr; gap:15px;">
                    <div class="form-layout">
                        <label>Select Addon Product <span style="color:red;">*</span></label>
                        <select name="fa_product_id" id="fa_product_select" required>
                            <option value="">-- Loading addon list... --</option>
                        </select>
                    </div>
                    <div class="form-layout">
                        <label>Quantity <span style="color:red;">*</span></label>
                        <input type="number" name="fa_quantity" id="fa_quantity" min="1" max="50" value="1" required>
                    </div>
                </div>

                <div class="form-layout">
                    <label>Reason / Justification <span style="color:red;">*</span></label>
                    <input type="text" name="fa_reason" placeholder="e.g. Kiosk mode error, customer compensation, complimentary upgrade" required>
                </div>

                <div style="background:#fffbeb; border:1px dashed #fbbf24; padding:10px; border-radius:8px; font-size:12px; color:#92400e; margin-bottom:12px;">
                    <i class="fas fa-info-circle"></i> <strong>Note:</strong> The original booking total amount will NOT change. The addon is fully complimentary and tracked separately sa <code>addon_redemptions</code> table.
                </div>

                <button type="submit" class="btn-execute" style="background:#16a34a;" onclick="return confirm('Confirm: Mag-aadd ng FREE ADDON sa booking na ito? Ito ay maglo-log sa system audit.');">
                    <i class="fas fa-gift"></i> Inject Free Addon (No Charge)
                </button>
            </form>
        </div>
<div id="delete-booking-tab" class="tab-content" style="display:none;">
            <h1><i class="fas fa-calendar-times" style="color:#b91c1c;"></i> Booking Override — Safe Deletion</h1>
            <div class="sub" style="color:#64748b; font-size:13px; margin-bottom:15px; line-height:1.5;">
                Gamitin ito para tuluyang burahin ang isang MAIN BOOKING (kasama ang items at addons nito). Ang total amount nito ay mababawas sa system dashboard records. <strong style="color:#b91c1c;">Strictly monitored by Audit Trail.</strong>
            </div>

            <div class="form-layout">
                <label>Booking Reference ID <span style="color:red;">*</span></label>
                <div style="display:flex; gap:10px;">
                    <input type="number" id="del_main_booking_lookup" placeholder="Enter Booking ID (e.g. 223)" style="flex:1;">
                    <button type="button" onclick="loadBookingForDelete()" style="background:#b91c1c; color:white; border:none; padding:10px 20px; border-radius:8px; font-weight:bold; cursor:pointer; white-space:nowrap;">
                        <i class="fas fa-search"></i> Load Booking
                    </button>
                </div>
            </div>

            <div id="del_main_booking_info" style="display:none; background:#fef2f2; border:1px solid #fca5a5; border-radius:10px; padding:15px; margin-bottom:15px;">
                <h3 style="margin:0 0 10px 0; font-size:14px; color:#991b1b;"><i class="fas fa-exclamation-triangle"></i> Target Booking for Deletion</h3>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; font-size:13px;">
                    <div><strong>Booking ID:</strong> #<span id="del_main_info_id">-</span></div>
                    <div><strong>Customer:</strong> <span id="del_main_info_name">-</span></div>
                    <div><strong>Email:</strong> <span id="del_main_info_email">-</span></div>
                    <div><strong>Total Amount:</strong> AED <span id="del_main_info_total" style="font-weight:bold; color:#b91c1c;">-</span></div>
                    <div><strong>Visit Date:</strong> <span id="del_main_info_visit">-</span></div>
                    <div><strong>Pay Status:</strong> <span id="del_main_info_status">-</span></div>
                </div>
            </div>

            <form action="super_gm_override.php" method="POST" id="deleteBookingForm" style="display:none;">
                <input type="hidden" name="action" value="delete_booking">
                <input type="hidden" name="del_booking_id" id="del_booking_id">

                <div class="form-layout">
                    <label>Reason for Deletion <span style="color:red;">*</span></label>
                    <input type="text" name="del_booking_reason" placeholder="e.g. Double booking, test data, customer cancelled, refund processed" required>
                </div>

                <button type="submit" class="btn-execute btn-danger" onclick="return confirm('WARNING: Sigurado ka ba na buburahin ang booking na ito? Mababawas ang AED amount sa system records at hindi na ito maibabalik.');">
                    <i class="fas fa-trash-alt"></i> Permanently Delete Booking & Deduct Amount
                </button>
            </form>
        </div>

    </div>
    
</div>

<script>
    // =================================================================
// [NEW] MAIN BOOKING DELETE HELPER
// =================================================================
function loadBookingForDelete() {
    const bid = document.getElementById('del_main_booking_lookup').value;
    if (!bid || bid <= 0) { alert("Please enter a valid Booking ID."); return; }
    
    // We can reuse the existing 'ajax_get_payment_info' endpoint to fetch booking metadata
    fetch(`super_gm_override.php?ajax_get_payment_info=1&booking_id=${bid}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert(data.error || "Booking not found.");
                document.getElementById('del_main_booking_info').style.display = 'none';
                document.getElementById('deleteBookingForm').style.display = 'none';
                return;
            }
            const b = data.booking;
            document.getElementById('del_main_info_id').textContent = b.booking_id;
            document.getElementById('del_main_info_name').textContent = b.customer_name || '-';
            document.getElementById('del_main_info_email').textContent = b.customer_email || '-';
            document.getElementById('del_main_info_total').textContent = parseFloat(b.total_amount||0).toFixed(2);
            document.getElementById('del_main_info_visit').textContent = b.visit_date || '-';
            document.getElementById('del_main_info_status').textContent = (b.payment_status || '-').toUpperCase();
            document.getElementById('del_booking_id').value = b.booking_id;

            document.getElementById('del_main_booking_info').style.display = 'block';
            document.getElementById('deleteBookingForm').style.display = 'block';
        })
        .catch(err => { alert("Network error. Please try again."); console.error(err); });
}
// LIVE RETRIEVAL DECODER & GATEWAY SECURITY NETWORK AUDITOR
function fetchLiveBookingBreakdown(bookingId, customerName, totalAmount) {
    document.getElementById('target_booking_id').value = bookingId;
    document.getElementById('target_customer_name').value = customerName;
    document.getElementById('target_manual_reference').value = "";
    const board = document.getElementById('item_decoder_board');
    const table = document.getElementById('decoded_items_table');
    const banner = document.getElementById('bank_status_banner');
    table.innerHTML = `<tr><td colspan="3" style="text-align:center; padding:10px; color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Extracting localized system vouchers basket data...</td></tr>`;
    banner.innerHTML = `<span><i class="fas fa-satellite-dish fa-spin"></i> Sending secure ping request to live N-Genius merchant network...</span>`;
    banner.className = "bank-status-banner bank-pending";
    banner.style.display = "flex";
    board.style.display = 'block';

    fetch(`super_gm_override.php?ajax_fetch_items=1&booking_id=${bookingId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.items.length > 0) {
                let html = `<tr style="border-bottom: 2px solid #cbd5e1; font-weight: bold; color: #475569;"><td style="padding: 6px 0;">Component Allocation Matrix Name</td><td style="text-align: center;">Qty</td><td style="text-align: right;">Rate Value</td></tr>`;
                data.items.forEach(item => {
                    html += `<tr style="color: #334155; font-weight: 500;"><td style="padding: 6px 0;">- ${item.name}</td><td style="text-align: center; font-weight: bold;">x${item.qty}</td><td style="text-align: right;">AED ${item.price}</td></tr>`;
                });
                html += `<tr style="border-top: 1px solid #e2e8f0; font-weight: bold; color: #003B72;"><td colspan="2" style="padding-top: 8px;">Invoice Claim Value:</td><td style="text-align: right; padding-top: 8px; font-size: 14px;">AED ${parseFloat(totalAmount).toFixed(2)}</td></tr>`;
                table.innerHTML = html;
            } else {
                table.innerHTML = `<tr><td colspan="3" style="text-align:center; color:#ef4444; padding:10px;">Empty component payload metrics returned.</td></tr>`;
            }
        });

    fetch(`super_gm_override.php?ajax_scan_gateway=1&booking_id=${bookingId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.state === 'CAPTURED' || data.state === 'AUTHORISED' || data.state === 'PURCHASED') {
                    banner.className = "bank-status-banner bank-success";
                    banner.innerHTML = `<span><i class="fas fa-check-circle"></i> <strong>MATCH FOUND!</strong> Bank Network reports this order is fully <strong>PAID</strong>.</span><button type="button" class="btn-autofill" onclick="autofillRefCode('${data.reference}')">Auto-Fill Reference</button>`;
                } else {
                    banner.className = "bank-status-banner bank-pending";
                    banner.innerHTML = `<span><i class="fas fa-times-circle"></i> <strong>NOT PAID:</strong> Gateway reports status state as [${data.state}]. Money did not clear.</span>`;
                }
            } else {
                banner.className = "bank-status-banner bank-pending";
                banner.innerHTML = `<span><i class="fas fa-exclamation-triangle"></i> <strong>Audit Skipping:</strong> ${data.error}</span>`;
            }
        })
        .catch(() => {
            banner.innerHTML = `<span><i class="fas fa-wifi"></i> Connection timeout during API packet translation.</span>`;
        });

    toggleActiveTab('retrieve-tab', document.querySelectorAll('.tab-trigger')[0]);
}

function autofillRefCode(codeValue) {
    document.getElementById('target_manual_reference').value = codeValue;
}

function toggleActiveTab(tabId, buttonElement) {
    document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
    document.querySelectorAll('.tab-trigger').forEach(b => b.classList.remove('active'));
    document.getElementById(tabId).style.display = 'block';
    buttonElement.classList.add('active');
    try { localStorage.setItem('sgm_active_tab', tabId); } catch(e) {}
}



// ============ PAYMENT METHOD OVERRIDE HELPERS ============
function loadPaymentInfo() {
    const bid = document.getElementById('ovr_booking_lookup').value;
    if (!bid || bid <= 0) { alert("Please enter a valid Booking ID."); return; }
    fetch(`super_gm_override.php?ajax_get_payment_info=1&booking_id=${bid}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert((data.error || "Booking not found."));
                document.getElementById('ovr_booking_info').style.display = 'none';
                document.getElementById('paymentOverrideForm').style.display = 'none';
                return;
            }
            const b = data.booking;
            document.getElementById('ovr_info_name').textContent = b.customer_name || '-';
            document.getElementById('ovr_info_email').textContent = b.customer_email || '-';
            document.getElementById('ovr_info_total').textContent = parseFloat(b.total_amount || 0).toFixed(2);
            document.getElementById('ovr_info_visit').textContent = b.visit_date || '-';
            document.getElementById('ovr_info_status').textContent = (b.payment_status || '-').toUpperCase();
            document.getElementById('ovr_info_method').textContent = b.payment_method || '(empty)';
            document.getElementById('ovr_booking_id').value = b.booking_id;
            document.getElementById('ovr_expected_total').textContent = parseFloat(b.total_amount || 0).toFixed(2);
            document.getElementById('ovr_booking_info').style.display = 'block';
            document.getElementById('paymentOverrideForm').style.display = 'block';
        })
        .catch(err => { alert("Network error. Please try again."); console.error(err); });
}

function toggleMixedFields(show) {
    const mixedDiv = document.getElementById('ovr_mixed_fields');
    const cashInp  = document.getElementById('ovr_cash_amount');
    const cardInp  = document.getElementById('ovr_card_amount');
    if (show) { mixedDiv.style.display = 'block'; cashInp.required = true; cardInp.required = true; }
    else { mixedDiv.style.display = 'none'; cashInp.required = false; cardInp.required = false; cashInp.value = ''; cardInp.value = ''; }
}

function validateMixedTotal() {
    const cash = parseFloat(document.getElementById('ovr_cash_amount').value) || 0;
    const card = parseFloat(document.getElementById('ovr_card_amount').value) || 0;
    const expected = parseFloat(document.getElementById('ovr_expected_total').textContent) || 0;
    const sum = cash + card;
    const warn = document.getElementById('ovr_mixed_warning');
    if (Math.abs(sum - expected) < 0.01) {
        warn.innerHTML = `<span style="color:#16a34a; font-weight:bold;"><i class="fas fa-check-circle"></i> Total matches: AED ${sum.toFixed(2)}</span>`;
    } else {
        const diff = (expected - sum).toFixed(2);
        warn.innerHTML = `<span style="color:#dc2626;">Sum is AED ${sum.toFixed(2)}. Need AED ${diff} more (or less).</span>`;
    }
}

// I-restore ang huling tab pagkatapos ng form POST reload
document.addEventListener('DOMContentLoaded', function() {
    let savedTab = null;
    try { savedTab = localStorage.getItem('sgm_active_tab'); } catch(e) {}
    if (!savedTab || !document.getElementById(savedTab)) return;
    let matchBtn = null;
    document.querySelectorAll('.tab-trigger').forEach(b => {
        if ((b.getAttribute('onclick') || '').indexOf("'" + savedTab + "'") !== -1) matchBtn = b;
    });
    if (!matchBtn) return;
    toggleActiveTab(savedTab, matchBtn);
    if (savedTab === 'fnb-payment-tab') loadFnbList();
    if (savedTab === 'fnb-delete-tab')  loadFnbDeleteList();
    if (savedTab === 'free-addon-tab')  loadAddonOptions();
});



// ============ F&B HELPERS (Options 4 & 5) ============
function loadFnbList() {
    const filter = document.getElementById('fnb_filter') ? document.getElementById('fnb_filter').value : 'all';
    const listBox = document.getElementById('fnb_quick_list');
    if (!listBox) return;
    listBox.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
    fetch(`super_gm_override.php?ajax_list_fnb_orders=1&filter=${filter}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success || data.orders.length === 0) {
                listBox.innerHTML = '<em style="color:#94a3b8;">No F&B orders found.</em>'; return;
            }
            let html = '<table style="width:100%; font-size:11px; border-collapse:collapse;">';
            html += '<tr style="background:#fef3c7; font-weight:bold;"><td style="padding:5px;">ID</td><td>Customer</td><td>Shop</td><td>Total</td><td>Method</td><td>Pay</td><td>Created</td><td></td></tr>';
            data.orders.forEach(o => {
                const payColor = o.payment_status === 'PAID' ? '#16a34a' : '#dc2626';
                html += `<tr style="border-bottom:1px solid #fde68a;"><td style="padding:5px;"><strong>#${o.id}</strong></td><td>${o.customer_name || '-'}</td><td style="font-size:10px; color:#475569;">${o.shop_number || '-'}</td><td>AED ${parseFloat(o.total_amount||0).toFixed(2)}</td><td>${o.payment_method || '-'}</td><td style="color:${payColor}; font-weight:bold;">${o.payment_status}</td><td style="font-size:10px; color:#64748b;">${o.created_at}</td><td><button type="button" onclick="quickPickFnb(${o.id}, 'payment')" style="background:#d97706; color:white; border:none; padding:3px 7px; font-size:10px; border-radius:3px; cursor:pointer;">Pay</button> <button type="button" onclick="quickPickFnb(${o.id}, 'delete')" style="background:#dc2626; color:white; border:none; padding:3px 7px; font-size:10px; border-radius:3px; cursor:pointer;">Del</button></td></tr>`;
            });
            html += '</table>';
            listBox.innerHTML = html;
        });
}

function quickPickFnb(orderId, mode) {
    if (mode === 'payment') {
        document.getElementById('fnb_order_lookup').value = orderId;
        loadFnbInfo('payment');
    } else {
        const delTabBtn = document.querySelectorAll('.tab-trigger')[4];
        toggleActiveTab('fnb-delete-tab', delTabBtn);
        const f = document.getElementById('fnb_del_filter');
        if (f) f.value = 'all';
        loadFnbDeleteList(orderId);   // load + auto-check yung pinili
    }
}

function loadFnbInfo(mode) {
    const inputId = (mode === 'payment') ? 'fnb_order_lookup' : 'fnb_del_lookup';
    const oid = document.getElementById(inputId).value;
    if (!oid || oid <= 0) { alert("Please enter a valid F&B Order ID."); return; }
    fetch(`super_gm_override.php?ajax_get_fnb_info=1&order_id=${oid}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert((data.error || "F&B Order not found.")); return; }
            const o = data.order;
            if (mode === 'payment') {
                document.getElementById('fnb_pay_name').textContent = o.customer_name || '-';
                document.getElementById('fnb_pay_phone').textContent = o.customer_phone || '-';
                document.getElementById('fnb_pay_total').textContent = parseFloat(o.total_amount||0).toFixed(2);
                document.getElementById('fnb_pay_shop').textContent = o.shop_number || '-';
                document.getElementById('fnb_pay_status').textContent = o.payment_status || '-';
                document.getElementById('fnb_pay_method').textContent = o.payment_method || '(empty)';
                document.getElementById('fnb_order_id').value = o.id;
                document.getElementById('fnb_expected_total').textContent = parseFloat(o.total_amount||0).toFixed(2);
                let itemsHtml = '<strong>Items:</strong> ';
                if (data.items.length === 0) itemsHtml += '<em>none</em>';
                else data.items.forEach(it => { itemsHtml += `${it.quantity}x ${it.product_name} (${it.size||'-'}) @ AED ${it.price} | `; });
                document.getElementById('fnb_pay_items').innerHTML = itemsHtml;
                document.getElementById('fnb_pay_info').style.display = 'block';
                document.getElementById('fnbPaymentForm').style.display = 'block';
            } else {
                document.getElementById('fnb_del_id').textContent = o.id;
                document.getElementById('fnb_del_name').textContent = o.customer_name || '-';
                document.getElementById('fnb_del_total').textContent = parseFloat(o.total_amount||0).toFixed(2);
                document.getElementById('fnb_del_paystatus').textContent = o.payment_status || '-';
                document.getElementById('fnb_del_created').textContent = o.created_at || '-';
                document.getElementById('fnb_del_logcount').textContent = data.log_count + ' record(s)';
                document.getElementById('del_fnb_order_id').value = o.id;
                let itemsHtml = '<strong><i class="fas fa-box"></i> Items to be removed:</strong><br>';
                if (data.items.length === 0) itemsHtml += '<em>No items linked.</em>';
                else data.items.forEach(it => { itemsHtml += `- ${it.quantity}x ${it.product_name} (${it.size||'-'}) - AED ${it.price}<br>`; });
                document.getElementById('fnb_del_items_box').innerHTML = itemsHtml;
                document.getElementById('fnb_del_info').style.display = 'block';
                document.getElementById('fnbDeleteForm').style.display = 'block';
            }
        })
        .catch(err => { alert("Network error. Please try again."); console.error(err); });
}

function toggleFnbMixed(show) {
    const div = document.getElementById('fnb_mixed_fields');
    const cash = document.getElementById('fnb_cash_amount');
    const card = document.getElementById('fnb_card_amount');
    if (show) { div.style.display = 'block'; cash.required = true; card.required = true; }
    else { div.style.display = 'none'; cash.required = false; card.required = false; cash.value = ''; card.value = ''; }
}

function validateFnbMixed() {
    const cash = parseFloat(document.getElementById('fnb_cash_amount').value) || 0;
    const card = parseFloat(document.getElementById('fnb_card_amount').value) || 0;
    const expected = parseFloat(document.getElementById('fnb_expected_total').textContent) || 0;
    const sum = cash + card;
    const warn = document.getElementById('fnb_mixed_warn');
    if (Math.abs(sum - expected) < 0.01) {
        warn.innerHTML = `<span style="color:#16a34a; font-weight:bold;"><i class="fas fa-check-circle"></i> Total matches: AED ${sum.toFixed(2)}</span>`;
    } else {
        warn.innerHTML = `<span style="color:#dc2626;">Sum is AED ${sum.toFixed(2)}. Expected AED ${expected.toFixed(2)}.</span>`;
    }
}



// =================================================================
// [NEW] FREE ADDON HELPERS (Option 6)
// =================================================================
let _faAddonsCache = null;

function loadAddonOptions() {
    const sel = document.getElementById('fa_product_select');
    if (!sel) return;
    if (_faAddonsCache) {
        renderAddonSelect(_faAddonsCache);
        return;
    }
    sel.innerHTML = '<option value="">-- Loading addon list... --</option>';
    fetch('super_gm_override.php?ajax_list_addons=1')
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                sel.innerHTML = '<option value="">Error loading addons</option>';
                return;
            }
            _faAddonsCache = data.addons;
            renderAddonSelect(data.addons);
        })
        .catch(err => {
            sel.innerHTML = '<option value="">Network error loading addons</option>';
            console.error(err);
        });
}

function renderAddonSelect(addons) {
    const sel = document.getElementById('fa_product_select');
    if (!addons || addons.length === 0) {
        sel.innerHTML = '<option value="">No addon products found</option>';
        return;
    }
    let html = '<option value="">-- Choose an addon --</option>';
    addons.forEach(a => {
        const priceLabel = parseFloat(a.price || 0).toFixed(2);
        html += `<option value="${a.product_id}">${a.product_id} - ${a.name} (Normal Price: AED ${priceLabel})</option>`;
    });
    sel.innerHTML = html;
}

function loadBookingForAddon() {
    const bid = document.getElementById('fa_booking_lookup').value;
    if (!bid || bid <= 0) {
        alert("Please enter a valid Booking ID.");
        return;
    }
    fetch(`super_gm_override.php?ajax_get_booking_addons=1&booking_id=${bid}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert(data.error || "Booking not found.");
                document.getElementById('fa_booking_info').style.display = 'none';
                document.getElementById('freeAddonForm').style.display = 'none';
                return;
            }
            const b = data.booking;
            document.getElementById('fa_info_id').textContent = b.booking_id;
            document.getElementById('fa_info_name').textContent = b.customer_name || '-';
            document.getElementById('fa_info_email').textContent = b.customer_email || '-';
            document.getElementById('fa_info_phone').textContent = b.customer_phone || '-';
            document.getElementById('fa_info_visit').textContent = b.visit_date || '-';
            const status = (b.payment_status || '-').toUpperCase();
            const statusEl = document.getElementById('fa_info_status');
            statusEl.textContent = status;
            statusEl.style.color = (status === 'PAID') ? '#16a34a' : '#dc2626';
            document.getElementById('fa_booking_id').value = b.booking_id;

            // Render existing addons
            const listEl = document.getElementById('fa_existing_addons_list');
            if (data.existing_addons && data.existing_addons.length > 0) {
                let html = '';
                data.existing_addons.forEach(a => {
                    const remaining = (a.quantity_total || 0) - (a.quantity_used || 0);
                    const statusBadge = a.status === 'used' ? 'used' : 'unused';
                    const color = remaining > 0 ? '#16a34a' : '#94a3b8';
                    html += `<div style="padding:4px 0; border-bottom:1px dotted #d1d5db;"><strong>${a.product_id}</strong> - ${a.product_name || '(unknown)'} | Total: <strong>${a.quantity_total}</strong>, Used: ${a.quantity_used}, <span style="color:${color}; font-weight:bold;">Remaining: ${remaining}</span> [${statusBadge}]</div>`;
                });
                listEl.innerHTML = html;
            } else {
                listEl.innerHTML = '<em style="color:#94a3b8;">Walang existing addons sa booking na ito.</em>';
            }

            document.getElementById('fa_booking_info').style.display = 'block';
            document.getElementById('freeAddonForm').style.display = 'block';
            // Make sure addon dropdown is loaded
            loadAddonOptions();
        })
        .catch(err => { alert("Network error. Please try again."); console.error(err); });
}
// ============ OPTION 5: BULK F&B DELETE (checkbox) ============
function loadFnbDeleteList(preCheckId) {
    const tbody = document.getElementById('fnb_del_tbody');
    if (!tbody) return;
    const filter = document.getElementById('fnb_del_filter') ? document.getElementById('fnb_del_filter').value : 'all';
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; color:#94a3b8; padding:30px;"><i class="fas fa-spinner fa-spin"></i> Loading orders...</td></tr>';
    fetch(`super_gm_override.php?ajax_list_fnb_orders=1&filter=${filter}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success || data.orders.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; color:#94a3b8; padding:30px;">No F&B orders found.</td></tr>';
                updateFnbDeleteCount(); return;
            }
            let html = '';
            data.orders.forEach(o => {
                const payColor = o.payment_status === 'PAID' ? '#16a34a' : '#dc2626';
                const checked = (preCheckId && parseInt(preCheckId) === parseInt(o.id)) ? 'checked' : '';
                html += `<tr>
                    <td style="text-align:center;"><input type="checkbox" class="fnb-del-cb" value="${o.id}" onchange="updateFnbDeleteCount()" ${checked}></td>
                    <td><strong>#${o.id}</strong></td>
                    <td>${o.customer_name || '-'}</td>
                    <td style="font-size:11px; color:#475569;">${o.shop_number || '-'}</td>
                    <td style="text-align:right; font-weight:600;">AED ${parseFloat(o.total_amount||0).toFixed(2)}</td>
                    <td style="color:${payColor}; font-weight:bold;">${o.payment_status}</td>
                    <td>${o.payment_method || '-'}</td>
                    <td style="font-size:10px; color:#64748b;">${o.created_at}</td>
                </tr>`;
            });
            tbody.innerHTML = html;
            updateFnbDeleteCount();
            filterFnbDeleteRows();
        })
        .catch(err => {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; color:#dc2626; padding:30px;">Network error loading orders.</td></tr>';
            console.error(err);
        });
}

function toggleAllFnbDelete(masterCb) {
    document.querySelectorAll('#fnb_del_tbody tr').forEach(row => {
        if (row.style.display === 'none') return;            // skip filtered-out rows
        const cb = row.querySelector('.fnb-del-cb');
        if (cb) cb.checked = masterCb.checked;
    });
    updateFnbDeleteCount();
}

function updateFnbDeleteCount() {
    const n = document.querySelectorAll('.fnb-del-cb:checked').length;
    const el = document.getElementById('fnb_del_count');
    if (el) el.textContent = n;
}

function filterFnbDeleteRows() {
    const q = (document.getElementById('fnb_del_search').value || '').toLowerCase();
    document.querySelectorAll('#fnb_del_tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

function submitMultiDelete() {
    const checked = Array.from(document.querySelectorAll('.fnb-del-cb:checked')).map(cb => cb.value);
    const reason  = (document.getElementById('fnb_del_reason').value || '').trim();
    if (checked.length === 0) { alert('Walang naka-check na order. Pumili muna ng idi-delete.'); return; }
    if (!reason) { alert('Reason is required for audit trail.'); document.getElementById('fnb_del_reason').focus(); return; }
    if (!confirm(`FINAL CONFIRMATION: Permanently delete ${checked.length} F&B order(s)? Hindi na ito maibabalik (may snapshot sa admin_audit).`)) return;

    const btn = document.getElementById('fnb_del_submit_btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';

    const fd = new FormData();
    fd.append('ajax_multi_delete_fnb', '1');
    fd.append('reason', reason);
    checked.forEach(id => fd.append('order_ids[]', id));

    fetch('super_gm_override.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash-alt"></i> Delete Selected (<span id="fnb_del_count">0</span>)';
            if (!data.success) { alert(data.error || 'Delete failed.'); return; }
            let msg = `${data.deleted_count} order(s) deleted successfully.`;
            if (data.failed && data.failed.length > 0) msg += `\nFailed: ${data.failed.join(', ')}`;
            alert(msg);
            document.getElementById('fnb_del_reason').value = '';
            const master = document.getElementById('fnb_del_check_all');
            if (master) master.checked = false;
            loadFnbDeleteList();   // refresh in place — nananatili sa Option 5
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash-alt"></i> Delete Selected (<span id="fnb_del_count">0</span>)';
            alert('Network error. Please try again.');
            console.error(err);
        });
}
</script>

</body>
</html>
