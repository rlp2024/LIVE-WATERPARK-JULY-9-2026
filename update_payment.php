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
        // 1. I-update ang payment_status para maging 'paid' AT i-save ang name ng nag-process sa 'cashier_name'
        $stmtUpdate = $pdo->prepare("UPDATE bookings SET payment_status = 'paid', cashier_name = ? WHERE booking_id = ?");
        $stmtUpdate->execute([$processed_by, $booking_id]);

        // 2. I-send AGAD ang response sa cashier - huwag nang antayin ang gate
        //    push at email (dati dito nade-delay ang "Accept Cash" hanggang
        //    matapos ang mabagal na email send / gate curl call)
        if (isset($_GET['ajax'])) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Payment processed and email sent.',
                'booking_id' => $booking_id
            ]);
        } else {
            header("Location: admin_dashboard.php?view=sales&msg=" . urlencode("Payment received for Booking #$booking_id"));
        }

        // I-release ang session lock + i-flush ang response bago ang mabibigat
        // na background work (gate push + SMTP email).
        @session_write_close();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            ignore_user_abort(true);
            @ob_end_flush();
            @flush();
        }

    } catch (Exception $e) {
        // Error BAGO pa masend ang response (hal. DB update fail).
        if (isset($_GET['ajax'])) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
        die("System Error: " . $e->getMessage());
    }

    // --- Pagkatapos makarating ang response: gate push + email (background) ---
    // Isolated try/catch para HINDI masira ang naipadalang JSON kung sakaling
    // mag-error ang gate o email.
    try {
        require_once __DIR__ . '/gate_sync.php';
        gate_push_booking($pdo, (int)$booking_id);
    } catch (\Throwable $e) {
        @error_log('gate_push_booking failed: ' . $e->getMessage());
    }

    try {
        $stmtBook = $pdo->prepare("SELECT * FROM bookings WHERE booking_id = ?");
        $stmtBook->execute([$booking_id]);
        $booking = $stmtBook->fetch(PDO::FETCH_ASSOC);

        if ($booking) {
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

            // I-send ang Email Receipt (Annual Pass -> Confirmation; normal -> Entry Receipt).
            if (!empty($booking['expiry_date'])) {
                sendBookingConfirmation($booking['customer_email'], $booking['customer_name'], $booking_id, $booking, $items);
            } else {
                sendEntryReceipt($booking['customer_email'], $booking['customer_name'], $booking_id, $booking, $items, $processed_by);
            }
        }
    } catch (\Throwable $e) {
        @error_log('update_payment post-response email failed: ' . $e->getMessage());
    }

    exit;
} else {
    die("Invalid request.");
}
?>