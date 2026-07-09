<?php
// Linisin ang background para walang makasagabal na spaces o warnings sa JSON
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

require 'db_connect.php';

header('Content-Type: application/json');

$phone = $_GET['phone'] ?? '';

if (empty($phone)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'No phone provided']);
    exit;
}

// 1. Linisin ang phone number (tanggalin spaces at dashes)
$clean_phone = preg_replace('/[^0-9]/', '', $phone);

// Kailangan at least 9 digits ang number para iwas maling tao
if(strlen($clean_phone) < 9) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Phone too short']);
    exit;
}

// 2. Kunin ang huling 9 digits (Tinanggal ang % sa dulo para exact match ng buntot ng number)
$search_term = '%' . substr($clean_phone, -9);

$found_name = '';
$found_email = '';
$found_company = '';
$found_country = '';
$is_found = false;

// --- QUERY 1: Hanapin sa GUEST_DETAILS (Inuna natin ito dahil nandito ang complete details tulad ng country at company, at ginawang ORDER BY id DESC para LATEST data lagi) ---
if (!$is_found) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM guest_details WHERE phone LIKE ? OR phone = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$search_term, $phone]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res && (!empty($res['first_name']) || !empty($res['last_name']))) {
            $found_name = trim(($res['first_name'] ?? '') . ' ' . ($res['last_name'] ?? ''));
            $found_email = $res['email'] ?? '';
            $found_company = $res['company_name'] ?? '';
            $found_country = $res['country'] ?? $res['nationality'] ?? '';
            $is_found = true;
        }
    } catch (Exception $e) { /* Ignore error */ }
}

// --- QUERY 2: Hanapin sa BOOKINGS ---
if (!$is_found) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE customer_phone LIKE ? OR customer_phone = ? ORDER BY booking_id DESC LIMIT 1");
        $stmt->execute([$search_term, $phone]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res && !empty($res['customer_name'])) {
            $found_name = $res['customer_name'];
            $found_email = $res['customer_email'] ?? '';
            $found_company = $res['company_name'] ?? '';
            $found_country = $res['country'] ?? $res['nationality'] ?? '';
            $is_found = true;
        }
    } catch (Exception $e) { /* Ignore error, proceed to next table */ }
}

// --- QUERY 3: Hanapin sa QR_WALLETS ---
if (!$is_found) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM qr_wallets WHERE phone LIKE ? OR phone = ? ORDER BY wallet_id DESC LIMIT 1");
        $stmt->execute([$search_term, $phone]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res && !empty($res['customer_name'])) {
            $found_name = $res['customer_name'];
            $found_email = $res['customer_email'] ?? '';
            $is_found = true;
        }
    } catch (Exception $e) { /* Ignore error */ }
}

// --- QUERY 4: Hanapin sa FNB_ORDERS ---
if (!$is_found) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM fnb_orders WHERE customer_phone LIKE ? OR customer_phone = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$search_term, $phone]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res && !empty($res['customer_name'])) {
            $found_name = $res['customer_name'];
            $found_email = $res['customer_email'] ?? '';
            $is_found = true;
        }
    } catch (Exception $e) { /* Ignore error */ }
}

// Linisin ang aksidenteng output bago ibato ang final data pabalik sa Kiosk
ob_end_clean();

if ($is_found && !empty(trim($found_name))) {
    echo json_encode([
        'success' => true, 
        'name' => trim($found_name), 
        'email' => trim($found_email),
        'company' => trim($found_company),
        'country' => trim($found_country)
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'No match found']);
}
exit;
?>