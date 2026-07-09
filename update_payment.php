<?php
// update_payment.php - Tumatanggap ng bayad at nagse-send ng resibo
session_start();
include_once 'db_connect.php';
include_once 'email_helper.php';

// Siguraduhing may admin na naka-login
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: admin_login.php");
    exit;
}

if (isset($_GET['booking_id'])) {
    $booking_id = (int)$_GET['booking_id'];
    
    // Kunin ang admin name sa session, or sa pinasa ng AJAX
    $admin_name = $_SESSION['admin_fullname'] ?? 'Receptionist';
    $processed_by = isset($_GET['processed_by']) ? $_GET['processed_by'] : $admin_name;

    try {
        $pdo->beginTransaction();

        // 1. I-update ang payment_status para maging 'paid' AT i-save ang name ng nag-process sa 'cashier_name'
        $stmtUpdate = $pdo->prepare("UPDATE bookings SET payment_status = 'paid', cashier_name = ? WHERE booking_id = ?");
        $stmtUpdate->execute([$processed_by, $booking_id]);

        // 2. Kunin ang booking details para sa email
        $stmtBook = $pdo->prepare("SELECT * FROM bookings WHERE booking_id = ?");
        $stmtBook->execute([$booking_id]);
        $booking = $stmtBook->fetch(PDO::FETCH_ASSOC);

        if ($booking) {
            // 3. Kunin ang mga items na binili
            $stmtItems = $pdo->prepare("SELECT product_id, quantity, price_per_item as price FROM booking_items WHERE booking_id = ?");
            $stmtItems->execute([$booking_id]);
            $rawItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

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

            // 4. I-send ang Email Receipt!
            // Kapag Annual Pass, Confirmation ang ise-send. Kung normal tickets, Entry Receipt na may pangalan ng Cashier.
            if (!empty($booking['expiry_date'])) {
                sendBookingConfirmation($booking['customer_email'], $booking['customer_name'], $booking_id, $booking, $items);
            } else {
                sendEntryReceipt($booking['customer_email'], $booking['customer_name'], $booking_id, $booking, $items, $processed_by);
            }
        }

        $pdo->commit();

        // BAGONG LOGIC: Check kung AJAX request
        if (isset($_GET['ajax'])) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Payment processed and email sent.',
                'booking_id' => $booking_id
            ]);
            exit;
        }

        // Fallback for non-ajax requests
        header("Location: admin_dashboard.php?view=sales&msg=" . urlencode("Payment received for Booking #$booking_id"));
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        
        if (isset($_GET['ajax'])) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
        die("System Error: " . $e->getMessage());
    }
} else {
    die("Invalid request.");
}
?>