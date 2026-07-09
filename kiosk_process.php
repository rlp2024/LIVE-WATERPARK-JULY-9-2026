<?php
/**
 * kiosk_process.php
 * Intermediary: receives kiosk form data, builds session cart + guest,
 * then internally includes process.php logic
 */
session_start();
include_once 'db_connect.php';

$_SESSION['kiosk_mode'] = true;

// ============================================================
// 1. BUILD CART (same logic as details.php)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['tickets'])) {
    header('Location: kiosk_book.php');
    exit;
}

$cart = [];
$total_price = 0;

if (isset($_POST['booking_date']) && !empty($_POST['booking_date'])) {
    $_SESSION['booking_date'] = $_POST['booking_date'];
} else {
    $_SESSION['booking_date'] = date('Y-m-d');
}

foreach ($_POST['tickets'] as $id => $qty) {
    $qty = (int)$qty;
    if ($qty <= 0) continue;

    // Try ticket_types first
    $stmt = $pdo->prepare("
        SELECT t.type_id, t.price, t.category, t.sub_label,
               p.product_id, p.name AS package_name
        FROM ticket_types t
        JOIN products p ON t.product_id = p.product_id
        WHERE t.type_id = ?
    ");
    $stmt->execute([$id]);
    $ticketVar = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($ticketVar) {
        $sub = !empty($ticketVar['sub_label']) ? ' ' . $ticketVar['sub_label'] : '';
        $displayName = trim($ticketVar['category'] . $sub);
        $cartItem = [
            'id' => 'type_' . $ticketVar['type_id'],
            'name' => $displayName,
            'price' => $ticketVar['price'],
            'quantity' => $qty,
            'subtotal' => $ticketVar['price'] * $qty,
        ];
        $cart['type_' . $id] = $cartItem;
        $total_price += $cartItem['subtotal'];
    } else {
        // Try products table
        $stmt2 = $pdo->prepare("SELECT product_id, name, price FROM products WHERE product_id = ?");
        $stmt2->execute([$id]);
        $prod = $stmt2->fetch(PDO::FETCH_ASSOC);
        if ($prod) {
            $cartItem = [
                'id' => 'prod_' . $prod['product_id'],
                'name' => $prod['name'],
                'price' => $prod['price'],
                'quantity' => $qty,
                'subtotal' => $prod['price'] * $qty,
            ];
            $cart['prod_' . $id] = $cartItem;
            $total_price += $cartItem['subtotal'];
        }
    }
}

if (empty($cart)) {
    header('Location: kiosk_book.php');
    exit;
}

$_SESSION['cart'] = $cart;
$_SESSION['original_total'] = $total_price;


// ============================================================
// 2. CALCULATE VAT (5%)
// ============================================================
$vat_amount = $total_price * 0.05;
$_SESSION['vat_amount'] = $vat_amount;
$_SESSION['total_price'] = $total_price + $vat_amount;

// ============================================================
// 3. BUILD GUEST SESSION (same as checkout.php)
// ============================================================
$first = trim($_POST['first_name'] ?? '');
$last = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$phone_code = trim($_POST['phone_code'] ?? '+971');
$company = trim($_POST['company_name'] ?? '');
$country = trim($_POST['country'] ?? '');
$are_you = $_POST['are_you'] ?? 'visitor';

if (!in_array($are_you, ['visitor', 'residence'], true)) {
    $are_you = 'visitor';
}

$_SESSION['guest'] = [
    'first_name'    => $first,
    'last_name'     => $last,
    'email'         => $email,
    'phone_code'    => $phone_code,
    'phone'         => $phone,
    'company_name'  => $company,
    'country'       => $country,
    'are_you'       => $are_you,
    'agreed_terms'  => isset($_POST['agree_terms']) ? 1 : 0,
    'agreed_refund_policy' => isset($_POST['agree_refund']) ? 1 : 0,
];

$_SESSION['guest_details'] = [
    'customer_name'  => trim($first . ' ' . $last),
    'customer_email' => $email,
    'customer_phone' => $phone,
    'phone_code'     => $phone_code,
    'company_name'   => !empty($company) ? $company : null,
    'nationality'    => !empty($country) ? $country : null,
    'customer_type'  => $are_you,
    'agreed_terms'   => isset($_POST['agree_terms']) ? 1 : 0,
    'agreed_refund_policy' => isset($_POST['agree_refund']) ? 1 : 0,
];

// ============================================================
// 4. CSRF Token
// ============================================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Ensure process.php recognizes us
$_POST['is_kiosk'] = '1';
$_POST['csrf_token'] = $_SESSION['csrf_token'];

// Remove annual pass flags (this is a normal booking)
unset($_SESSION['is_annual_pass']);
unset($_SESSION['expiry_date']);

// ============================================================
// 5. FORWARD TO process.php (include it directly)
// ============================================================
include 'process.php';
