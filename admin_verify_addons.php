<?php
session_start();
include_once 'db_connect.php';
include_once 'email_helper.php';

// Timezone
date_default_timezone_set('Asia/Dubai');

// Security Check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

$message = "";
$messageType = "";
$booking = null;
$addons = [];
$step = 1;
$lastRedeemedCode = null;

// SOUND FLAGS for frontend
$playSound = ""; // "success" | "error" | "ready" | ""

// NEW: popup payload (shown on redeem result)
$popup = [
    'show' => false,
    'title' => '',
    'text' => '',
    'type' => 'success', // success|error
];

// ✅ Add-on catalog for BUY modal
$addonCatalog = [];
try {
    $stmtC = $pdo->prepare("SELECT product_id, name, price FROM products WHERE category_id = 6 ORDER BY name ASC");
    $stmtC->execute();
    $addonCatalog = $stmtC->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $addonCatalog = [];
}

// Helper: parse addon qr like "12-ADD2"
function parseAddonQR($text) {
    $text = trim($text);
    if (preg_match('/^(\d+)\-(ADD\d+)$/i', $text, $m)) {
        return [(int)$m[1], strtoupper($m[2]), $m[0]];
    }
    return [null, null, null];
}

// Helper: parse booking-only scan: "12"
function parseBookingOnly($text) {
    $text = trim($text);
    if (preg_match('/^\d+$/', $text)) return (int)$text;
    return null;
}

// Fetch booking + all add-ons with totals/used/remaining (and ensure addon_redemptions rows exist)
function loadBookingAddons(PDO $pdo, int $bookingId, &$bookingOut, &$addonsOut) {
    $stmtB = $pdo->prepare("SELECT * FROM bookings WHERE booking_id=?");
    $stmtB->execute([$bookingId]);
    $booking = $stmtB->fetch(PDO::FETCH_ASSOC);

    if (!$booking) return ['ok' => false, 'msg' => 'BOOKING NOT FOUND.', 'type' => 'error'];
    if ($booking['payment_status'] !== 'paid') return ['ok' => false, 'msg' => 'UNPAID BOOKING. ADD-ONS NOT ALLOWED.', 'type' => 'error'];

    $stmt = $pdo->prepare("
        SELECT
            p.product_id,
            p.name,
            bi.quantity AS purchased_qty,
            bi.price_per_item AS price,
            CONCAT(?, '-', p.product_id) AS unique_code
        FROM booking_items bi
        JOIN products p ON bi.product_id = p.product_id
        WHERE bi.booking_id = ? AND p.category_id = 6
        ORDER BY p.name ASC
    ");
    $stmt->execute([$bookingId, $bookingId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        $bookingOut = $booking;
        $addonsOut = [];
        return ['ok' => true, 'msg' => 'NO ADD-ONS FOUND IN THIS BOOKING.', 'type' => 'error'];
    }

    $stmtIns = $pdo->prepare("
        INSERT INTO addon_redemptions (booking_id, product_id, unique_code, quantity_total, quantity_used, status)
        VALUES (?, ?, ?, ?, 0, 'unused')
        ON DUPLICATE KEY UPDATE quantity_total = VALUES(quantity_total)
    ");

    foreach ($rows as $r) {
        $stmtIns->execute([$bookingId, $r['product_id'], $r['unique_code'], (int)$r['purchased_qty']]);
    }

    $stmtR = $pdo->prepare("
        SELECT
            ar.unique_code,
            ar.quantity_total,
            ar.quantity_used,
            ar.status,
            ar.redeemed_at,
            ar.redeemed_by
        FROM addon_redemptions ar
        WHERE ar.booking_id = ?
    ");
    $stmtR->execute([$bookingId]);
    $redeems = $stmtR->fetchAll(PDO::FETCH_ASSOC);

    $redeemMap = [];
    foreach ($redeems as $rr) {
        $redeemMap[$rr['unique_code']] = $rr;
    }

    $addons = [];
    foreach ($rows as $r) {
        $code = $r['unique_code'];
        $rr = $redeemMap[$code] ?? null;

        $total = (int)($rr['quantity_total'] ?? $r['purchased_qty']);
        $used  = (int)($rr['quantity_used'] ?? 0);
        $remaining = max(0, $total - $used);

        $addons[] = [
            'product_id' => $r['product_id'],
            'name' => $r['name'],
            'price' => (float)$r['price'],
            'unique_code' => $code,
            'total' => $total,
            'used' => $used,
            'remaining' => $remaining,
            'status' => ($remaining <= 0) ? 'used' : 'unused',
            'redeemed_at' => $rr['redeemed_at'] ?? null,
            'redeemed_by' => $rr['redeemed_by'] ?? null,
        ];
    }

    $bookingOut = $booking;
    $addonsOut = $addons;
    return ['ok' => true, 'msg' => 'MAIN TICKET VERIFIED. NOW SCAN ADD-ON QR TO REDEEM.', 'type' => 'success'];
}

// Redeem exactly 1 use for scanned addon unique_code (bookingId-ADDx)
function redeemAddonOne(PDO $pdo, string $uniqueCode, string $admin, &$bookingOut, &$addonsOut, &$msgOut, &$typeOut, &$addonNameOut, &$remainingOut, &$emailSentOut) {
    $addonNameOut = "";
    $remainingOut = null;
    $emailSentOut = false;

    try {
        $pdo->beginTransaction();

        $stmtLock = $pdo->prepare("SELECT * FROM addon_redemptions WHERE unique_code=? FOR UPDATE");
        $stmtLock->execute([$uniqueCode]);
        $row = $stmtLock->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->rollBack();
            $msgOut = "ADD-ON QR NOT REGISTERED.";
            $typeOut = "error";
            return false;
        }

        $total = (int)$row['quantity_total'];
        $used  = (int)$row['quantity_used'];
        $remaining = max(0, $total - $used);

        if ($remaining <= 0) {
            $pdo->rollBack();
            $msgOut = "ADD-ON ALREADY FULLY USED.";
            $typeOut = "error";
            return false;
        }

        $newUsed = $used + 1;
        $newRemaining = max(0, $total - $newUsed);
        $newStatus = ($newRemaining <= 0) ? 'used' : 'unused';

        $stmtUpdate = $pdo->prepare("
            UPDATE addon_redemptions
            SET quantity_used=?, status=?, redeemed_at=NOW(), redeemed_by=?
            WHERE unique_code=?
        ");
        $stmtUpdate->execute([$newUsed, $newStatus, $admin, $uniqueCode]);

        if (!preg_match('/^(\d+)\-(ADD\d+)$/i', $uniqueCode, $m)) {
            $pdo->commit();
            $msgOut = "REDEEMED.";
            $typeOut = "success";
            $remainingOut = $newRemaining;
            return true;
        }

        $bookingId = (int)$m[1];
        $productId = strtoupper($m[2]);

        $stmtB = $pdo->prepare("SELECT * FROM bookings WHERE booking_id=?");
        $stmtB->execute([$bookingId]);
        $booking = $stmtB->fetch(PDO::FETCH_ASSOC);

        $stmtP = $pdo->prepare("SELECT name FROM products WHERE product_id=? LIMIT 1");
        $stmtP->execute([$productId]);
        $prod = $stmtP->fetch(PDO::FETCH_ASSOC);
        $addonName = $prod ? $prod['name'] : $productId;

        $pdo->commit();

        loadBookingAddons($pdo, $bookingId, $bookingOut, $addonsOut);

        if ($booking && !empty($booking['customer_email']) && function_exists('sendAddonRemainingUpdate')) {
            sendAddonRemainingUpdate(
                $booking['customer_email'],
                $booking['customer_name'],
                $bookingId,
                $addonName,
                $productId,
                $newUsed,
                $total,
                $newRemaining,
                $admin
            );
            $emailSentOut = true;
        }

        $addonNameOut = $addonName;
        $remainingOut = $newRemaining;

        $msgOut = "REDEEMED: $addonName (Remaining: $newRemaining)";
        $typeOut = "success";
        return true;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msgOut = "SYSTEM ERROR: " . $e->getMessage();
        $typeOut = "error";
        return false;
    }
}

// ✅ If coming back from top-up success, show message
if (isset($_GET['msg']) && $_GET['msg'] === 'topup_success') {
    $message = "Add-ons purchased successfully! You can now redeem them.";
    $messageType = "success";
    $popup['show'] = true;
    $popup['type'] = 'success';
    $popup['title'] = "Purchase Successful!";
    $popup['text'] = "Add-ons have been added to this booking. Thank you!";
}

// ✅ Auto-load booking if booking_id is passed in URL (useful after payment)
if (isset($_GET['booking_id'])) {
    $bid = (int)$_GET['booking_id'];
    if ($bid > 0) {
        $res = loadBookingAddons($pdo, $bid, $booking, $addons);
        $step = $res['ok'] ? 2 : 1;
        if (empty($message)) {
            $message = $res['msg'];
            $messageType = $res['type'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // MAIN SCAN (Step 1)
    if (isset($_POST['scan_main'])) {
        $scan = trim($_POST['scan_main']);
        $bookingId = parseBookingOnly($scan);

        if (!$bookingId) {
            $message = "INVALID MAIN TICKET. Please scan the MAIN ticket ID (example: 12).";
            $messageType = "error";
            $playSound = "error";
            $step = 1;
        } else {
            $res = loadBookingAddons($pdo, $bookingId, $booking, $addons);
            $message = $res['msg'];
            $messageType = $res['type'];

            if ($res['ok']) {
                $step = 2;
                $playSound = "ready";
            } else {
                $step = 1;
                $playSound = "error";
            }
        }
    }

    // ADD-ON SCAN (Step 2) -> AUTO REDEEM 1
    if (isset($_POST['scan_addon']) && isset($_POST['booking_id'])) {
        $scan = trim($_POST['scan_addon']);
        $currentBookingId = (int)$_POST['booking_id'];
        $admin = $_SESSION['admin_fullname'];

        $res = loadBookingAddons($pdo, $currentBookingId, $booking, $addons);
        $step = $res['ok'] ? 2 : 1;

        if ($step !== 2) {
            $message = $res['msg'];
            $messageType = $res['type'];
            $playSound = "error";
        } else {
            [$bidFromAddon, $pid, $uniqueCode] = parseAddonQR($scan);

            if (!$bidFromAddon || !$pid || !$uniqueCode) {
                $message = "INVALID ADD-ON QR. Example: 12-ADD2";
                $messageType = "error";
                $playSound = "error";
            } elseif ($bidFromAddon != $currentBookingId) {
                $message = "THIS ADD-ON DOES NOT MATCH THE CURRENT MAIN TICKET.";
                $messageType = "error";
                $playSound = "error";
            } else {
                $addonName = "";
                $remaining = null;
                $emailSent = false;

                $ok = redeemAddonOne($pdo, $uniqueCode, $admin, $booking, $addons, $message, $messageType, $addonName, $remaining, $emailSent);

                if ($ok) {
                    $lastRedeemedCode = $uniqueCode;
                    $playSound = "success";

                    $popup['show']  = true;
                    $popup['type']  = 'success';
                    $popup['title'] = "Redeemed Successfully!";
                    $popup['text']  = $addonName
                        ? ($addonName . " redeemed. Remaining: " . (int)$remaining . ".")
                        : "Add-on redeemed successfully.";

                    $popup['text'] .= $emailSent
                        ? " Update has been sent to your email. Thank you!"
                        : " Thank you!";

                } else {
                    $playSound = "error";

                    $popup['show']  = true;
                    $popup['type']  = 'error';
                    $popup['title'] = "Redeem Failed";
                    $popup['text']  = $message ?: "Please try again.";
                }

                $step = 2;
            }
        }
    }

    // ✅ BUY ADD-ONS (Top-up flow) -> prepare cart + guest then go checkout
    if (isset($_POST['buy_addons']) && isset($_POST['booking_id'])) {

        $currentBookingId = (int)$_POST['booking_id'];

        // validate booking paid
        $stmtB = $pdo->prepare("SELECT * FROM bookings WHERE booking_id=? LIMIT 1");
        $stmtB->execute([$currentBookingId]);
        $bk = $stmtB->fetch(PDO::FETCH_ASSOC);

        if (!$bk || $bk['payment_status'] !== 'paid') {
            $message = "Cannot buy add-ons. Booking not found or unpaid.";
            $messageType = "error";
            $playSound = "error";
            $step = 1;
        } else {
            $qtyMap = $_POST['qty'] ?? [];

            $cart = [];
            $total = 0;

            foreach ($qtyMap as $pid => $qtyRaw) {
                $qty = (int)$qtyRaw;
                if ($qty <= 0) continue;

                $pid = strtoupper(trim($pid));

                $stmtP = $pdo->prepare("SELECT product_id, name, price FROM products WHERE product_id=? AND category_id=6 LIMIT 1");
                $stmtP->execute([$pid]);
                $p = $stmtP->fetch(PDO::FETCH_ASSOC);
                if (!$p) continue;

                $price = (float)$p['price'];
                $subtotal = $price * $qty;

                $cart[] = [
                    'id' => 'prod_' . $p['product_id'],
                    'name' => $p['name'],
                    'quantity' => $qty,
                    'price' => $price,
                    'subtotal' => $subtotal
                ];
                $total += $subtotal;
            }

            if (empty($cart)) {
                $message = "Please select at least 1 add-on to purchase.";
                $messageType = "error";
                $playSound = "error";
                $step = 2;
            } else {
                $full = trim($bk['customer_name'] ?? 'Guest');
                $parts = preg_split('/\s+/', $full, 2);
                $first = $parts[0] ?? 'Guest';
                $last  = $parts[1] ?? '';

                $_SESSION['cart'] = $cart;
                $_SESSION['guest'] = [
                    'first_name' => $first,
                    'last_name'  => $last,
                    'email'      => $bk['customer_email'] ?? '',
                    'phone'      => $bk['customer_phone'] ?? '',
                    'country'    => 'UAE'
                ];

                // kiosk safe defaults
                $_SESSION['kiosk_mode'] = true;
                $_SESSION['booking_date'] = date('Y-m-d');

                // TOPUP FLAG used by process.php
                $_SESSION['topup_for_booking_id'] = $currentBookingId;

                // remove discount for safety
                unset($_SESSION['discount_applied']);

                header("Location: checkout.php?mode=kiosk&topup=1");
                exit;
            }
        }
    }

    // CANCEL / NEW SCAN
    if (isset($_POST['cancel_session'])) {
        header("Location: admin_verify_addons.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Add-on Scanner - Ajman Water Park</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

  <style>
    :root{
      --bg:#0b1220;
      --text:#eaf0ff;
      --muted:#a7b3d6;
      --ok:#22c55e;
      --bad:#ef4444;
      --warn:#f59e0b;
      --accent:#7c3aed;
      --accent2:#4f46e5;
      --border:rgba(255,255,255,.08);
      --shadow:0 18px 45px rgba(0,0,0,.45);
      --radius:18px;
    }
    *{ box-sizing:border-box; }
    body{
      margin:0;
      font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, "Helvetica Neue", Helvetica, sans-serif;
      background: radial-gradient(1200px 600px at 20% 10%, rgba(124,58,237,.25), transparent 60%),
                  radial-gradient(1000px 500px at 90% 40%, rgba(79,70,229,.22), transparent 60%),
                  var(--bg);
      color:var(--text);
      min-height:100vh;
    }

    .topbar{
      position:sticky; top:0; z-index:50;
      background: rgba(15,26,51,.72);
      backdrop-filter: blur(10px);
      border-bottom:1px solid var(--border);
    }
    .topbar-inner{
      max-width:1100px;
      margin:0 auto;
      padding:14px 16px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
    }
    .brand{ display:flex; align-items:center; gap:10px; font-weight:800; letter-spacing:.4px; }
    .brand i{ color: var(--accent); font-size:18px; }
    .sub{ font-weight:600; color:var(--muted); font-size:13px; }
    .actions{ display:flex; align-items:center; gap:10px; }
    .pill{
      display:flex; align-items:center; gap:8px;
      padding:10px 12px; border:1px solid var(--border); border-radius:999px;
      color:var(--text); background: rgba(255,255,255,.04);
      font-size:13px; white-space:nowrap;
    }
    .logout{
      text-decoration:none;
      border:1px solid rgba(239,68,68,.35);
      color:#ffd1d1;
      background: rgba(239,68,68,.10);
      padding:10px 12px;
      border-radius:999px;
      font-weight:800;
      transition:.2s;
    }

    .wrap{ max-width:1100px; margin:0 auto; padding:18px 16px 28px; }
    .grid{ display:grid; grid-template-columns: 420px 1fr; gap:16px; }
    @media (max-width: 980px){ .grid{ grid-template-columns: 1fr; } }

    .panel{
      background: rgba(16,31,63,.65);
      border:1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      overflow:hidden;
    }
    .panel-head{ padding:16px 16px 12px; border-bottom:1px solid var(--border); }
    .panel-head h2{ margin:0; font-size:16px; letter-spacing:.2px; }
    .panel-head p{ margin:6px 0 0; color:var(--muted); font-size:13px; line-height:1.3; }
    .panel-body{ padding:16px; }

    .reader{ border-radius: 14px; overflow:hidden; border:1px solid var(--border); background:#000; }
    #reader__dashboard_section{ padding:10px !important; background: rgba(255,255,255,.05) !important; border-top:1px solid var(--border) !important; }
    #reader video{ width:100% !important; height:auto !important; object-fit:cover !important; }

    .msg{
      padding:12px 14px;
      border-radius: 14px;
      border:1px solid var(--border);
      font-weight:800;
      margin:0 0 14px;
      display:flex;
      align-items:center;
      gap:10px;
      line-height:1.25;
    }
    .msg.success{ background: rgba(34,197,94,.12); border-color: rgba(34,197,94,.28); color:#d7ffe6; }
    .msg.error{ background: rgba(239,68,68,.12); border-color: rgba(239,68,68,.28); color:#ffd4d4; }

    .field{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .input{
      flex:1; min-width: 220px;
      padding:16px 14px; border-radius: 14px; border:1px solid var(--border);
      background: rgba(255,255,255,.04);
      color:var(--text);
      font-size:18px; font-weight:800; outline:none;
    }
    .btn{
      padding:16px 16px;
      border-radius:14px;
      border:1px solid var(--border);
      background: linear-gradient(135deg, rgba(124,58,237,.95), rgba(79,70,229,.95));
      color:white;
      font-size:16px;
      font-weight:900;
      cursor:pointer;
      white-space:nowrap;
    }
    .btn-ghost{ background: rgba(255,255,255,.06); border:1px solid var(--border); }
    .btn-danger{
      background: rgba(239,68,68,.15);
      border-color: rgba(239,68,68,.35);
    }

    .booking-card{
      display:flex; flex-direction:column; gap:8px;
      padding:14px; border-radius: 16px; border:1px solid var(--border);
      background: rgba(255,255,255,.03);
    }
    .booking-title{ display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
    .badge{
      padding:8px 10px; border-radius:999px; font-size:12px; font-weight:900;
      border:1px solid var(--border);
      background: rgba(255,255,255,.05);
      color:var(--muted);
    }
    .badge.paid{ color:#d7ffe6; border-color: rgba(34,197,94,.28); background: rgba(34,197,94,.10); }
    .badge.pending{ color:#fff0d1; border-color: rgba(245,158,11,.28); background: rgba(245,158,11,.10); }
    .booking-main{ font-size:18px; font-weight:900; margin:0; }
    .booking-sub{ margin:0; color:var(--muted); font-size:13px; line-height:1.35; }

    .addon-list{ display:flex; flex-direction:column; gap:10px; margin-top:12px; }
    .addon{
      border:1px solid var(--border);
      background: rgba(255,255,255,.03);
      border-radius: 16px;
      padding:12px;
      display:flex;
      gap:12px;
      align-items:stretch;
      justify-content:space-between;
      flex-wrap:wrap;
    }
    .addon-left{ min-width: 220px; flex: 1; }
    .addon-name{ font-weight:1000; margin:0 0 6px; font-size:15px; }
    .addon-meta{ margin:0; color:var(--muted); font-size:12.5px; line-height:1.35; }
    .stats{ display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
    .stat{
      padding:8px 10px; border-radius:999px; border:1px solid var(--border);
      font-size:12px; font-weight:900;
      background: rgba(255,255,255,.04);
      color: var(--muted);
    }
    .stat strong{ color:var(--text); }
    .remain{ color:#d7ffe6; border-color: rgba(34,197,94,.28); background: rgba(34,197,94,.10); }
    .used{ color:#ffe8c7; border-color: rgba(245,158,11,.28); background: rgba(245,158,11,.10); }
    .done{ color:#ffd4d4; border-color: rgba(239,68,68,.28); background: rgba(239,68,68,.10); }
    .kbd{
      display:inline-flex;
      padding:3px 8px;
      border-radius: 999px;
      border:1px solid var(--border);
      background: rgba(255,255,255,.05);
      font-weight:900;
      font-size:12px;
      color: var(--text);
    }

    /* Idle overlay */
    .idle-overlay{
      display:none;
      position:fixed;
      inset:0;
      background: rgba(0,0,0,.65);
      z-index:9999;
      align-items:center;
      justify-content:center;
      padding:16px;
    }
    .idle-box{
      width:min(520px, 100%);
      background: rgba(16,31,63,.92);
      border:1px solid var(--border);
      border-radius: 18px;
      box-shadow: var(--shadow);
      padding:18px;
      text-align:center;
    }
    .idle-title{ font-size:18px; font-weight:1000; margin:0 0 8px; }
    .idle-sub{ margin:0 0 14px; color: var(--muted); font-weight:700; font-size:13px; line-height:1.35; }
    .idle-actions{ display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }

    /* Redeem popup (reused for messages) */
    .popup-overlay{
      display:none;
      position:fixed;
      inset:0;
      background: rgba(0,0,0,.65);
      z-index:10000;
      align-items:center;
      justify-content:center;
      padding:16px;
    }
    .popup-card{
      width:min(520px, 100%);
      background: rgba(16,31,63,.96);
      border:1px solid var(--border);
      border-radius: 18px;
      box-shadow: var(--shadow);
      padding:18px;
      text-align:center;
      position:relative;
      overflow:hidden;
    }
    .popup-topline{
      position:absolute;
      top:0; left:0;
      height:5px;
      width:100%;
      background: linear-gradient(90deg, rgba(34,197,94,.95), rgba(16,185,129,.95));
    }
    .popup-topline.error{
      background: linear-gradient(90deg, rgba(239,68,68,.95), rgba(245,158,11,.95));
    }
    .popup-icon{
      width:64px; height:64px;
      display:flex; align-items:center; justify-content:center;
      border-radius:18px;
      margin:8px auto 10px;
      background: rgba(34,197,94,.12);
      border:1px solid rgba(34,197,94,.28);
      color:#d7ffe6;
      font-size:28px;
    }
    .popup-icon.error{
      background: rgba(239,68,68,.12);
      border:1px solid rgba(239,68,68,.28);
      color:#ffd4d4;
    }
    .popup-title{
      margin:0 0 8px;
      font-size:18px;
      font-weight:1000;
    }
    .popup-text{
      margin:0 0 14px;
      color: var(--muted);
      font-weight:700;
      font-size:13px;
      line-height:1.45;
    }
    .popup-actions{
      display:flex;
      gap:10px;
      justify-content:center;
      flex-wrap:wrap;
    }
    .popup-mini{
      margin-top:10px;
      font-size:12px;
      color: rgba(167,179,214,.85);
      font-weight:700;
    }
  </style>
</head>
<body>

<div class="topbar">
  <div class="topbar-inner">
    <div class="brand">
      <i class="fa-solid fa-qrcode"></i>
      <div>
        <div>Add-on Scanner</div>
        <div class="sub">Kiosk Flow: Scan main ticket → then scan add-on QR (auto-redeem)</div>
      </div>
    </div>
    <div class="actions">
      <div class="pill"><i class="fa-solid fa-user-shield"></i> <?php echo htmlspecialchars($_SESSION['admin_fullname']); ?></div>
      <a class="logout" href="admin_logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
  </div>
</div>

<div class="wrap">
  <div class="grid">

    <div class="panel">
      <div class="panel-head">
        <h2>
          <?php if ($step === 1): ?>
            Step 1: Scan your MAIN ticket to redeem
          <?php else: ?>
            Step 2: Scan your ADD-ON QR to redeem (auto)
          <?php endif; ?>
        </h2>
        <p>
          <?php if ($step === 1): ?>
            Scan the main ticket ID only (example: <span class="kbd">12</span>)
          <?php else: ?>
            <?php if (!empty($booking) && isset($booking['booking_id'])): ?>
              Scan an add-on QR like <span class="kbd"><?php echo (int)$booking['booking_id']; ?>-ADD2</span>. Each scan redeems <b>1</b>.
            <?php else: ?>
              Scan an add-on QR like <span class="kbd">12-ADD2</span>. Each scan redeems <b>1</b>.
            <?php endif; ?>
          <?php endif; ?>
        </p>
      </div>

      <div class="panel-body">

        <?php if (!empty($message)): ?>
          <div class="msg <?php echo ($messageType === 'success') ? 'success' : 'error'; ?>">
            <i class="fa-solid <?php echo ($messageType === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <div><?php echo htmlspecialchars($message); ?></div>
          </div>
        <?php endif; ?>

        <div class="reader" id="reader"></div>

        <?php if ($step === 1): ?>
          <form method="POST" id="scanMainForm" style="margin-top:12px;">
            <div class="field">
              <input class="input" type="text" name="scan_main" id="scan_input" placeholder="Scan MAIN ticket..." autocomplete="off" autofocus required>
              <button class="btn" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Verify</button>
            </div>
          </form>
        <?php else: ?>
          <form method="POST" id="scanAddonForm" style="margin-top:12px;">
            <input type="hidden" name="booking_id" value="<?php echo (int)$booking['booking_id']; ?>">
            <div class="field">
              <input class="input" type="text" name="scan_addon" id="scan_input" placeholder="Scan ADD-ON QR..." autocomplete="off" autofocus required>
              <button class="btn" type="submit"><i class="fa-solid fa-bolt"></i> Redeem</button>

              <!-- ✅ New Scan must be type="button" so it won't submit / trigger required -->
              <button class="btn btn-ghost" type="button" onclick="window.location.href='admin_verify_addons.php'">
                <i class="fa-solid fa-rotate-left"></i> New Scan
              </button>

              <!-- ✅ Buy Add-ons button -->
              <button class="btn btn-ghost" type="button" onclick="openBuyModal()">
                <i class="fa-solid fa-cart-plus"></i> Buy Add-ons
              </button>
            </div>
          </form>
        <?php endif; ?>

      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <h2>Add-ons</h2>
        <p>After scanning the main ticket, scan add-on QRs. Email updates are sent automatically.</p>
      </div>

      <div class="panel-body">

        <?php if (!$booking): ?>
          <div class="booking-card" style="background:rgba(255,255,255,.02);">
            <p class="booking-main" style="margin:0;">Waiting for main ticket...</p>
            <p class="booking-sub">Scan the MAIN ticket first to load the add-ons list.</p>
          </div>
        <?php else: ?>

          <div class="booking-card">
            <div class="booking-title">
              <div>
                <p class="booking-main">Order #<?php echo str_pad((int)$booking['booking_id'], 6, '0', STR_PAD_LEFT); ?></p>
                <p class="booking-sub">
                  <?php echo htmlspecialchars($booking['customer_name']); ?>
                  <?php if (!empty($booking['customer_email'])): ?>
                    • <?php echo htmlspecialchars($booking['customer_email']); ?>
                  <?php endif; ?>
                </p>
              </div>
              <div class="badge <?php echo ($booking['payment_status'] === 'paid') ? 'paid' : 'pending'; ?>">
                <?php echo strtoupper($booking['payment_status']); ?>
              </div>
            </div>
          </div>

          <?php if (!$addons): ?>
            <div class="msg error" style="margin-top:12px;">
              <i class="fa-solid fa-circle-xmark"></i>
              <div>No add-ons found in this booking.</div>
            </div>
          <?php else: ?>
            <div class="addon-list">
              <?php foreach ($addons as $a): ?>
                <?php
                  $isDone = ((int)$a['remaining'] <= 0);
                  $highlight = ($lastRedeemedCode && strcasecmp($lastRedeemedCode, $a['unique_code']) === 0);
                ?>
                <div class="addon" style="<?php echo $highlight ? 'outline:2px solid rgba(34,197,94,.6);' : ''; ?>">
                  <div class="addon-left">
                    <p class="addon-name"><?php echo htmlspecialchars($a['name']); ?> <span style="opacity:.7; font-weight:900;">(<?php echo htmlspecialchars($a['product_id']); ?>)</span></p>
                    <p class="addon-meta">Code: <span class="kbd"><?php echo htmlspecialchars($a['unique_code']); ?></span></p>
                    <div class="stats">
                      <span class="stat"><strong>Total:</strong> <?php echo (int)$a['total']; ?></span>
                      <span class="stat used"><strong>Used:</strong> <?php echo (int)$a['used']; ?></span>
                      <?php if ($isDone): ?>
                        <span class="stat done"><strong>Remaining:</strong> 0</span>
                      <?php else: ?>
                        <span class="stat remain"><strong>Remaining:</strong> <?php echo (int)$a['remaining']; ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

        <?php endif; ?>

      </div>
    </div>

  </div>
</div>

<!-- Idle Overlay -->
<div class="idle-overlay" id="idleOverlay">
  <div class="idle-box">
    <p class="idle-title">Need some time to think?</p>
    <p class="idle-sub">If you don’t respond, this kiosk will reset automatically for the next guest.</p>
    <div class="idle-actions">
      <button class="btn" type="button" onclick="idleContinue()"><i class="fa-solid fa-play"></i> Continue</button>
      <button class="btn btn-ghost btn-danger" type="button" onclick="idleCancel()"><i class="fa-solid fa-xmark"></i> Cancel</button>
    </div>
  </div>
</div>

<!-- Redeem Popup -->
<div class="popup-overlay" id="popupOverlay">
  <div class="popup-card" id="popupCard">
    <div class="popup-topline" id="popupTopline"></div>

    <div class="popup-icon" id="popupIcon">
      <i class="fa-solid fa-circle-check"></i>
    </div>

    <p class="popup-title" id="popupTitle">Done!</p>
    <p class="popup-text" id="popupText">Message</p>

    <div class="popup-actions">
      <button class="btn" type="button" onclick="closePopup()">
        <i class="fa-solid fa-check"></i> OK
      </button>
    </div>

    <div class="popup-mini" id="popupMini">Ready for the next scan…</div>
  </div>
</div>

<!-- ✅ BUY ADD-ONS MODAL -->
<div class="popup-overlay" id="buyOverlay" style="display:none;">
  <div class="popup-card" style="text-align:left;">
    <div class="popup-topline"></div>

    <p class="popup-title" style="text-align:center; margin-top:10px;">
      <i class="fa-solid fa-cart-plus"></i> Purchase More Add-ons
    </p>

    <p class="popup-text" style="text-align:center;">
      Select add-ons and quantity, then proceed to payment.
    </p>

    <form method="POST" id="buyForm">
      <input type="hidden" name="buy_addons" value="1">
      <input type="hidden" name="booking_id" value="<?php echo (int)($booking['booking_id'] ?? 0); ?>">

      <div style="display:flex; flex-direction:column; gap:10px; max-height:320px; overflow:auto; padding:10px; border:1px solid rgba(255,255,255,.08); border-radius:14px; background: rgba(255,255,255,.03);">
        <?php foreach($addonCatalog as $p): ?>
          <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px; border:1px solid rgba(255,255,255,.08); border-radius:14px; background: rgba(255,255,255,.02);">
            <div style="min-width:180px;">
              <div style="font-weight:900;"><?php echo htmlspecialchars($p['name']); ?></div>
              <div style="font-size:12px; color:rgba(167,179,214,.9); font-weight:800;">
                <?php echo htmlspecialchars($p['product_id']); ?> • AED <?php echo number_format((float)$p['price'], 2); ?>
              </div>
            </div>

            <div style="display:flex; align-items:center; gap:8px;">
              <button type="button" class="btn btn-ghost" style="padding:10px 12px;" onclick="stepQty('<?php echo htmlspecialchars($p['product_id']); ?>', -1)">-</button>
              <input
                class="input"
                style="width:90px; min-width:90px; padding:12px 10px; text-align:center; font-size:16px;"
                type="number"
                min="0"
                name="qty[<?php echo htmlspecialchars($p['product_id']); ?>]"
                id="qty_<?php echo htmlspecialchars($p['product_id']); ?>"
                value="0"
                oninput="calcBuyTotal()"
              >
              <button type="button" class="btn btn-ghost" style="padding:10px 12px;" onclick="stepQty('<?php echo htmlspecialchars($p['product_id']); ?>', 1)">+</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px; padding:10px; border-radius:14px; border:1px solid rgba(255,255,255,.08); background: rgba(255,255,255,.03);">
        <div style="font-weight:900;">Total</div>
        <div style="font-weight:1000; color:#d7ffe6;">AED <span id="buyTotal">0.00</span></div>
      </div>

      <div class="popup-actions" style="margin-top:14px;">
        <button class="btn btn-ghost btn-danger" type="button" onclick="closeBuyModal()">
          <i class="fa-solid fa-xmark"></i> Cancel
        </button>
        <button class="btn" type="submit">
          <i class="fa-solid fa-credit-card"></i> Proceed to Payment
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  // -------- SOUND ENGINE (No MP3 needed) --------
  let audioCtx = null;
  let audioUnlocked = false;

  function ensureAudio(){
    if(!audioCtx){
      audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
    if(audioCtx.state === "suspended"){
      audioCtx.resume();
    }
    audioUnlocked = true;
  }

  function beep(freq, durationMs, type="sine", gain=0.08){
    if(!audioUnlocked) return;
    const o = audioCtx.createOscillator();
    const g = audioCtx.createGain();
    o.type = type;
    o.frequency.value = freq;

    g.gain.setValueAtTime(0.0001, audioCtx.currentTime);
    g.gain.exponentialRampToValueAtTime(gain, audioCtx.currentTime + 0.01);
    g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + (durationMs/1000));

    o.connect(g);
    g.connect(audioCtx.destination);

    o.start();
    o.stop(audioCtx.currentTime + (durationMs/1000) + 0.02);
  }

  function soundSuccess(){ beep(880, 90, "sine", 0.10); setTimeout(()=>beep(1175, 110, "sine", 0.10), 110); }
  function soundError(){ beep(220, 160, "square", 0.08); setTimeout(()=>beep(180, 170, "square", 0.08), 170); }
  function soundReady(){ beep(660, 70, "sine", 0.07); }

  ["click","touchstart","keydown"].forEach(evt=>{
    window.addEventListener(evt, ()=>{
      if(!audioUnlocked) ensureAudio();
    }, {passive:true, once:true});
  });

  const SOUND = <?php echo json_encode($playSound); ?>;
  window.addEventListener("load", ()=>{
    try { ensureAudio(); } catch(e){}
    if(SOUND === "success") soundSuccess();
    if(SOUND === "error") soundError();
    if(SOUND === "ready") soundReady();
  });

  // -------- QR Scanner --------
  let scanner;
  const STEP = <?php echo (int)$step; ?>;

  function startScanner(){
    if(scanner) return;

    scanner = new Html5QrcodeScanner("reader", { fps: 10, qrbox: { width: 260, height: 260 } }, false);
    scanner.render((text)=>{
      try { ensureAudio(); } catch(e){}

      const inp = document.getElementById("scan_input");
      inp.value = text;

      try { scanner.clear(); } catch(e){}

      if (STEP === 1) {
        document.getElementById("scanMainForm").submit();
      } else {
        document.getElementById("scanAddonForm").submit();
      }
    }, (err)=>{});
  }
  window.onload = startScanner;

  // -------- Idle logic --------
  let idleTimer = null;
  let overlayTimer = null;
  const idleOverlay = document.getElementById("idleOverlay");

  function resetIdle(){
    clearTimeout(idleTimer);
    clearTimeout(overlayTimer);

    if(STEP !== 2) return;

    idleTimer = setTimeout(()=>{
      idleOverlay.style.display = "flex";
      overlayTimer = setTimeout(()=>{
        window.location.href = "admin_verify_addons.php";
      }, 10000);
    }, 20000);
  }

  function idleContinue(){
    idleOverlay.style.display = "none";
    resetIdle();
  }

  function idleCancel(){
    window.location.href = "admin_verify_addons.php";
  }

  ["click","touchstart","keydown","mousemove","scroll"].forEach(evt=>{
    window.addEventListener(evt, resetIdle, {passive:true});
  });

  resetIdle();

  // -------- Redeem Popup Logic --------
  const POPUP = <?php echo json_encode($popup); ?>;

  const popupOverlay = document.getElementById("popupOverlay");
  const popupTopline = document.getElementById("popupTopline");
  const popupIcon    = document.getElementById("popupIcon");
  const popupTitle   = document.getElementById("popupTitle");
  const popupText    = document.getElementById("popupText");
  const popupMini    = document.getElementById("popupMini");

  function openPopup(){
    if(!POPUP || !POPUP.show) return;

    popupTitle.textContent = POPUP.title || "Done!";
    popupText.textContent  = POPUP.text || "";
    popupMini.textContent  = "Ready for the next scan…";

    if (POPUP.type === "error") {
      popupTopline.classList.add("error");
      popupIcon.classList.add("error");
      popupIcon.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i>';
    } else {
      popupTopline.classList.remove("error");
      popupIcon.classList.remove("error");
      popupIcon.innerHTML = '<i class="fa-solid fa-circle-check"></i>';
    }

    popupOverlay.style.display = "flex";

    // Pause idle timer while popup is open
    clearTimeout(idleTimer);
    clearTimeout(overlayTimer);

    // Auto close after 5s on success
    if (POPUP.type !== "error") {
      setTimeout(()=>closePopup(), 5000);
    }
  }

  function closePopup(){
    popupOverlay.style.display = "none";
    resetIdle();
    const inp = document.getElementById("scan_input");
    if(inp) inp.focus();
  }

  popupOverlay.addEventListener("click", (e)=>{
    if(e.target === popupOverlay) closePopup();
  });

  window.addEventListener("load", openPopup);

  // -------- BUY ADD-ONS MODAL --------
  function openBuyModal(){
    const o = document.getElementById('buyOverlay');
    if(o) o.style.display = 'flex';
    calcBuyTotal();
  }
  function closeBuyModal(){
    const o = document.getElementById('buyOverlay');
    if(o) o.style.display = 'none';
  }

  document.getElementById('buyOverlay')?.addEventListener('click', (e)=>{
    if(e.target.id === 'buyOverlay') closeBuyModal();
  });

  function stepQty(pid, delta){
    const el = document.getElementById('qty_' + pid);
    if(!el) return;
    let v = parseInt(el.value || '0', 10);
    v = Math.max(0, v + delta);
    el.value = v;
    calcBuyTotal();
  }

  function calcBuyTotal(){
    const prices = <?php
      $priceMap = [];
      foreach($addonCatalog as $p){ $priceMap[$p['product_id']] = (float)$p['price']; }
      echo json_encode($priceMap);
    ?>;

    let total = 0;
    for(const pid in prices){
      const el = document.getElementById('qty_' + pid);
      if(!el) continue;
      const q = parseInt(el.value || '0', 10) || 0;
      total += (prices[pid] * q);
    }
    document.getElementById('buyTotal').textContent = total.toFixed(2);
  }
</script>

</body>
</html>
