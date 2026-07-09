<?php
// view_pass.php
session_start();
include_once 'db_connect.php';

// 1. Security Check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    die("Access Denied");
}
if (!isset($_GET['booking_id'])) {
    die("Invalid Request");
}

$booking_id = (int)$_GET['booking_id'];
$action = $_GET['action'] ?? 'view'; // 'view' or 'download'
$action = ($action === 'download') ? 'download' : 'view';

// IMPORTANT: prevent warnings breaking jpeg output
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// 2. Load Email Helper
if (file_exists(__DIR__ . '/email_helper.php')) {
    require_once __DIR__ . '/email_helper.php';
} else {
    die("Error: email_helper.php not found.");
}

// 3. Fetch Booking Details
$stmt = $pdo->prepare("
    SELECT b.*, bi.product_id
    FROM bookings b
    JOIN booking_items bi ON b.booking_id = bi.booking_id
    WHERE b.booking_id = ?
    LIMIT 1
");
$stmt->execute([$booking_id]);
$details = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$details) {
    die("Booking not found.");
}

// 4. Determine Background Image based on Product ID
$pid = $details['product_id'];
$passImageFile = '';

if ($pid === 'AP1') {
    $passImageFile = __DIR__ . '/Images/annual_passes/annual_passes-SILVER-V3.jpg';
} elseif ($pid === 'AP2') {
    $passImageFile = __DIR__ . '/Images/annual_passes/annual_passes-GOLD-V3.jpg';
} elseif ($pid === 'AP3') {
    $passImageFile = __DIR__ . '/Images/annual_passes/annual_passes-PLATINUM-V3.jpg';
} else {
    die("Not an Annual Pass transaction (Product ID: " . htmlspecialchars($pid) . ")");
}

if (!file_exists($passImageFile)) {
    die("Background not found: " . htmlspecialchars($passImageFile));
}

// 5. Generate Image
$expiryDate = (!empty($details['expiry_date']))
    ? date("F d, Y", strtotime($details['expiry_date']))
    : 'N/A';

// IMPORTANT: pass ONLY the filename (not absolute path)
// generatePassImage will resolve it properly
$faceFile = null;
if (!empty($details['face_image_path'])) {
    $faceFile = basename($details['face_image_path']); // example: face_1767....jpg
}

$imagePath = generatePassImage($passImageFile, $booking_id, $details['customer_name'], $expiryDate, $faceFile);

if ($imagePath && file_exists($imagePath)) {

    // Clean filename for download
    $cleanName = preg_replace('/[^a-zA-Z0-9]/', '_', $details['customer_name']);
    $filename = "AjmanPass_" . $cleanName . ".jpg";

    // CRITICAL: clean buffers before output headers
    while (ob_get_level()) { ob_end_clean(); }

    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($imagePath));

    if ($action === 'download') {
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    } else {
        header('Content-Disposition: inline; filename="' . basename($filename) . '"');
    }

    readfile($imagePath);
    exit;

} else {
    echo "Error generating pass image. Check background, GD, fonts, and face image path.";
}
?>
