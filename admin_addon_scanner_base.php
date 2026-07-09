<?php
// admin_addon_scanner_base.php - UPDATED (FASTER REDEEM RESPONSE, SAME UI/LOGIC)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
session_start();
include_once 'db_connect.php';
include_once 'email_helper.php';

date_default_timezone_set('Asia/Dubai');

// Security Check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

// ----------------------------------------------------
// SCANNER CONFIG (WRAPPER CHECK)
// ----------------------------------------------------
if (isset($SCANNER_PID)) {
    $SCANNER_PID = strtoupper(trim($SCANNER_PID));
} else {
    die("<h1>Configuration Error</h1><p>Please open the specific scanner file (e.g., scan_zipline.php), not this base file directly.</p>");
}

if (isset($SCANNER_SECTION)) {
    $SCANNER_SECTION = trim($SCANNER_SECTION);
}

$SCANNER_KEYWORD = isset($SCANNER_KEYWORD) ? trim($SCANNER_KEYWORD) : null;
$SCANNER_SECTION = isset($SCANNER_SECTION) ? trim($SCANNER_SECTION) : null;

$targetPid = null;
$targetName = null;

try {
    if ($SCANNER_PID) {
        $stmtT = $pdo->prepare("SELECT product_id, name FROM products WHERE product_id=? AND category_id=6 LIMIT 1");
        $stmtT->execute([$SCANNER_PID]);
        $t = $stmtT->fetch(PDO::FETCH_ASSOC);
        if ($t) {
            $targetPid = strtoupper($t['product_id']);
            $targetName = $t['name'];
        }
    } elseif ($SCANNER_KEYWORD) {
        $like = "%" . $SCANNER_KEYWORD . "%";
        $stmtT = $pdo->prepare("SELECT product_id, name FROM products WHERE category_id=6 AND name LIKE ? ORDER BY name ASC LIMIT 1");
        $stmtT->execute([$like]);
        $t = $stmtT->fetch(PDO::FETCH_ASSOC);
        if ($t) {
            $targetPid = strtoupper($t['product_id']);
            $targetName = $t['name'];
        }
    }
} catch (Exception $e) {}

if (!$targetPid || !$targetName) {
    die("Scanner configuration error: Product not found in products table (category_id=6).");
}

if (empty($SCANNER_SECTION)) {
    $SCANNER_SECTION = $targetName;
}

$message = "";
$messageType = "";
$booking = null;
$addons = [];
$step = 1;
$lastRedeemedCode = null;
$playSound = "";

// popup payload
$popup = [
    'show' => false,
    'title' => '',
    'text' => '',
    'type' => 'success',
];

// Add-on catalog for BUY modal
$addonCatalog = [];
try {
    $stmtC = $pdo->prepare("SELECT product_id, name, price FROM products WHERE category_id = 6 ORDER BY name ASC");
    $stmtC->execute();
    $addonCatalog = $stmtC->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $addonCatalog = [];
}

// ----------------------------------------------------
// HELPER FUNCTIONS
// ----------------------------------------------------

function resolveBookingId($pdo, $input) {
    $input = trim($input);
    if (preg_match('/^\d+$/', $input)) return (int)$input;
    try {
        $stmt = $pdo->prepare("SELECT booking_id FROM ticket_instances WHERE ticket_code = ? LIMIT 1");
        $stmt->execute([$input]);
        $res = $stmt->fetchColumn();
        if ($res) return (int)$res;
    } catch (Exception $e) {}
    if (preg_match('/^(\d+)-/', $input, $matches)) return (int)$matches[1];
    return null;
}

function parseAddonQR($text) {
    $text = trim($text);
    if (preg_match('/^(\d+)\-(ADD\d+)$/i', $text, $m)) {
        return [(int)$m[1], strtoupper($m[2]), $m[0]];
    }
    return [null, null, null];
}

function loadBookingAddons(PDO $pdo, int $bookingId, &$bookingOut, &$addonsOut) {
    $stmtB = $pdo->prepare("SELECT * FROM bookings WHERE booking_id=?");
    $stmtB->execute([$bookingId]);
    $booking = $stmtB->fetch(PDO::FETCH_ASSOC);

    if (!$booking) return ['ok' => false, 'msg' => 'BOOKING NOT FOUND.', 'type' => 'error'];
    if ($booking['payment_status'] !== 'paid') return ['ok' => false, 'msg' => 'UNPAID BOOKING.', 'type' => 'error'];

    $stmt = $pdo->prepare("
        SELECT 
            p.product_id, 
            p.name, 
            SUM(bi.quantity) AS purchased_qty,
            MAX(bi.price_per_item) AS price,
            CONCAT(?, '-', p.product_id) AS unique_code
        FROM booking_items bi
        JOIN products p ON bi.product_id = p.product_id
        WHERE bi.booking_id = ? AND p.category_id = 6
        GROUP BY p.product_id
        ORDER BY p.name ASC
    ");
    $stmt->execute([$bookingId, $bookingId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        $bookingOut = $booking;
        $addonsOut = [];
        return ['ok' => true, 'msg' => 'NO ADD-ONS FOUND.', 'type' => 'error'];
    }

    $stmtIns = $pdo->prepare("
        INSERT INTO addon_redemptions (booking_id, product_id, unique_code, quantity_total, quantity_used, status)
        VALUES (?, ?, ?, ?, 0, 'unused')
        ON DUPLICATE KEY UPDATE quantity_total = VALUES(quantity_total)
    ");
    foreach ($rows as $r) $stmtIns->execute([$bookingId, $r['product_id'], $r['unique_code'], (int)$r['purchased_qty']]);

    $stmtR = $pdo->prepare("SELECT unique_code, quantity_total, quantity_used, status, redeemed_at, redeemed_by FROM addon_redemptions WHERE booking_id = ?");
    $stmtR->execute([$bookingId]);
    $redeems = $stmtR->fetchAll(PDO::FETCH_ASSOC);
    $redeemMap = [];
    foreach ($redeems as $rr) $redeemMap[$rr['unique_code']] = $rr;

    $addons = [];
    foreach ($rows as $r) {
        $code = $r['unique_code'];
        $rr = $redeemMap[$code] ?? null;
        $total = (int)($rr['quantity_total'] ?? $r['purchased_qty']);
        $used  = (int)($rr['quantity_used'] ?? 0);
        $addons[] = [
            'product_id'   => $r['product_id'],
            'name'         => $r['name'],
            'price'        => (float)$r['price'],
            'unique_code'  => $code,
            'total'        => $total,
            'used'         => $used,
            'remaining'    => max(0, $total - $used),
            'status'       => (($total - $used) <= 0) ? 'used' : 'unused',
            'redeemed_at'  => $rr['redeemed_at'] ?? null,
            'redeemed_by'  => $rr['redeemed_by'] ?? null,
        ];
    }

    $bookingOut = $booking;
    $addonsOut  = $addons;
    return ['ok' => true, 'msg' => 'VERIFIED. SCAN ADD-ON TO REDEEM.', 'type' => 'success'];
}

/**
 * SPEED FIX (same behavior / same UI):
 * - Removed the extra loadBookingAddons() inside redeem (it was redundant because the redirect reloads page anyway)
 * - Removed sending email inside redeem (we will send after redirect response is flushed)
 *   -> UI becomes instant like your gate scanner.
 */
function redeemAddonOne(
    PDO $pdo,
    string $uniqueCode,
    string $admin,
    string $scannerSection,
    &$bookingOut,
    &$addonsOut,
    &$msgOut,
    &$typeOut,
    &$addonNameOut,
    &$remainingOut,
    &$emailPayloadOut
) {
    $addonNameOut = "";
    $remainingOut = null;
    $emailPayloadOut = null;

    try {
        $pdo->beginTransaction();

        $stmtLock = $pdo->prepare("SELECT * FROM addon_redemptions WHERE unique_code=? FOR UPDATE");
        $stmtLock->execute([$uniqueCode]);
        $row = $stmtLock->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->rollBack();
            $msgOut  = "QR NOT REGISTERED.";
            $typeOut = "error";
            return false;
        }

        $total = (int)$row['quantity_total'];
        $used  = (int)$row['quantity_used'];

        if (($total - $used) <= 0) {
            $pdo->rollBack();
            $msgOut  = "ALREADY USED.";
            $typeOut = "error";
            return false;
        }

        $newUsed      = $used + 1;
        $newRemaining = $total - $newUsed;

        $stmtUpdate = $pdo->prepare("UPDATE addon_redemptions SET quantity_used=?, status=?, redeemed_at=NOW(), redeemed_by=? WHERE unique_code=?");
        $stmtUpdate->execute([$newUsed, ($newRemaining <= 0 ? 'used' : 'unused'), $admin, $uniqueCode]);

        try {
            $pdo->prepare("
                INSERT INTO addon_redemption_logs
                (booking_id, product_id, unique_code, scanner_section, redeemed_by, quantity_used_after, remaining_after)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                (int)$row['booking_id'],
                strtoupper($row['product_id']),
                $uniqueCode,
                $scannerSection,
                $admin,
                $newUsed,
                $newRemaining
            ]);
        } catch (Exception $e) {}

        // Parse booking + product from unique code for email payload & message
        $bid = null; $pid = null;
        if (preg_match('/^(\d+)\-(ADD\d+)$/i', $uniqueCode, $m)) {
            $bid = (int)$m[1];
            $pid = strtoupper($m[2]);
        }

        $addonName = $pid ?: (strtoupper($row['product_id']) ?: "ADD-ON");

        // Get product name (small query)
        if ($pid) {
            $stmtP = $pdo->prepare("SELECT name FROM products WHERE product_id=? LIMIT 1");
            $stmtP->execute([$pid]);
            $prod = $stmtP->fetch(PDO::FETCH_ASSOC);
            if ($prod && !empty($prod['name'])) $addonName = $prod['name'];
        }

        // Build email payload (we will send AFTER redirect flush)
        if ($bid) {
            $stmtB = $pdo->prepare("SELECT customer_email, customer_name FROM bookings WHERE booking_id=? LIMIT 1");
            $stmtB->execute([$bid]);
            $bookingMini = $stmtB->fetch(PDO::FETCH_ASSOC);

            if ($bookingMini && !empty($bookingMini['customer_email'])) {
                $emailPayloadOut = [
                    'to'        => $bookingMini['customer_email'],
                    'name'      => $bookingMini['customer_name'] ?? '',
                    'bookingId' => $bid,
                    'addonName' => $addonName,
                    'productId' => $pid ?: strtoupper($row['product_id']),
                    'used'      => $newUsed,
                    'total'     => $total,
                    'remaining' => $newRemaining,
                    'admin'     => $admin,
                ];
            }
        }

        $pdo->commit();

        // IMPORTANT: Do NOT call loadBookingAddons() here (page will reload anyway)
        $addonNameOut = $addonName;
        $remainingOut = $newRemaining;
        $msgOut       = "REDEEMED: $addonName";
        $typeOut      = "success";
        return true;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msgOut  = "ERROR.";
        $typeOut = "error";
        return false;
    }
}

// ----------------------------------------------------
// GET: topup success
// ----------------------------------------------------
if (isset($_GET['msg']) && $_GET['msg'] === 'topup_success') {
    $message = "Add-ons purchased successfully!";
    $messageType = "success";
    $popup['show'] = true;
    $popup['type'] = 'success';
    $popup['title'] = "Purchase Successful!";
}

// ----------------------------------------------------
// GET: load booking addons
// ----------------------------------------------------
if (isset($_GET['booking_id'])) {
    $bid = (int)$_GET['booking_id'];
    if ($bid > 0) {
        $res = loadBookingAddons($pdo, $bid, $booking, $addons);
        $step = $res['ok'] ? 2 : 1;
        if (empty($message)) { $message = $res['msg']; $messageType = $res['type']; }
    }
}

// ----------------------------------------------------
// PROCESS POST REQUESTS (UPDATED FOR SPEED)
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // --- 1. SCAN MAIN TICKET ---
if (isset($_POST['scan_main'])) {
    $scan = trim($_POST['scan_main']);

    // ✅ BLOCK add-on QR
    if (preg_match('/^\d+\-(ADD\d+)$/i', $scan)) {
        $msg = urlencode("Please scan MAIN TICKET first (Not Add-on).");
        header("Location: ?msg=$msg&type=error&sound=error");
        exit;
    }

    // ✅ ACCEPT ANY MAIN TICKET format: BOOKINGID-XX-XXXX (examples: 1-01-83A, 124-02-A95, 124-25-5)
    if (!preg_match('/^\d+\-\d+\-[A-Z0-9]+$/i', $scan) && !ctype_digit($scan)) {
        $msg = urlencode("INVALID MAIN TICKET.");
        header("Location: ?msg=$msg&type=error&sound=error");
        exit;
    }

    $bookingId = resolveBookingId($pdo, $scan);

    if (!$bookingId) {
        $msg = urlencode("INVALID TICKET.");
        header("Location: ?msg=$msg&type=error&sound=error");
        exit;
    }

    header("Location: ?booking_id=$bookingId&msg=VERIFIED&type=success&sound=ready");
    exit;
}

    // --- 2. SCAN ADD-ON (REDEEM) ---
    if (isset($_POST['scan_addon']) && isset($_POST['booking_id'])) {
        $scan = trim($_POST['scan_addon']);
        $currentBookingId = (int)$_POST['booking_id'];

        // Read session values early then release session lock (helps speed under load)
        $admin = $_SESSION['admin_fullname'] ?? 'Admin';
        session_write_close();

        list($bidFromAddon, $pid, $uniqueCode) = parseAddonQR($scan);

        if (!$bidFromAddon || !$pid || !$uniqueCode) {
            $msg = urlencode("INVALID QR.");
            header("Location: ?booking_id=$currentBookingId&msg=$msg&type=error&sound=error");
            exit;
        } elseif ($bidFromAddon != $currentBookingId) {
            $msg = urlencode("WRONG TICKET (Belongs to Order #$bidFromAddon).");
            header("Location: ?booking_id=$currentBookingId&msg=$msg&type=error&sound=error");
            exit;
        } elseif (strcasecmp($pid, $targetPid) !== 0) {
            $msg = urlencode("WRONG ITEM. This scanner is for $targetName only.");
            header("Location: ?booking_id=$currentBookingId&msg=$msg&type=error&sound=error&popup_title=Wrong Add-on");
            exit;
        } else {
            $addonName = "";
            $remaining = null;
            $msgOut = "";
            $typeOut = "";
            $emailPayload = null;

            // Redeem (DB only + build email payload, NO heavy reload, NO email here)
            $ok = redeemAddonOne(
                $pdo,
                $uniqueCode,
                $admin,
                $SCANNER_SECTION,
                $booking,
                $addons,
                $msgOut,
                $typeOut,
                $addonName,
                $remaining,
                $emailPayload
            );

            $encodedMsg   = urlencode($msgOut);
            $encodedTitle = urlencode($ok ? "Redeemed!" : "Already Used!");
            $encodedSound = $ok ? "success" : "error";

            // Redirect immediately (instant UI)
            header("Location: ?booking_id=$currentBookingId&msg=$encodedMsg&type=$typeOut&sound=$encodedSound&popup_show=1&popup_title=$encodedTitle&popup_text=$encodedMsg&last_code=$uniqueCode");

            // Flush response then send email (same UX, faster perceived speed)
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            // Email AFTER response flush (only if payload exists)
            if ($ok && $emailPayload && function_exists('sendAddonRemainingUpdate')) {
                try {
                    sendAddonRemainingUpdate(
                        $emailPayload['to'],
                        $emailPayload['name'],
                        $emailPayload['bookingId'],
                        $emailPayload['addonName'],
                        $emailPayload['productId'],
                        $emailPayload['used'],
                        $emailPayload['total'],
                        $emailPayload['remaining'],
                        $emailPayload['admin']
                    );
                } catch (Exception $e) {
                    // ignore email errors (do not slow kiosk)
                }
            }
            exit;
        }
    }
}

// ----------------------------------------------------
// HANDLE GET MESSAGES (DISPLAY AFTER REDIRECT)
// ----------------------------------------------------
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $messageType = $_GET['type'] ?? 'info';
}
if (isset($_GET['sound'])) {
    $playSound = $_GET['sound'];
}
if (isset($_GET['last_code'])) {
    $lastRedeemedCode = $_GET['last_code'];
}
if (isset($_GET['popup_show'])) {
    $popup['show']  = true;
    $popup['type']  = $_GET['type'] ?? 'success';
    $popup['title'] = $_GET['popup_title'] ?? '';
    $popup['text']  = $_GET['popup_text'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title><?php echo htmlspecialchars($targetName); ?> Scanner</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

  <style>
    /* BASE CSS */
    :root{ --bg:#0b1220; --text:#eaf0ff; --muted:#a7b3d6; --ok:#22c55e; --bad:#ef4444; --warn:#f59e0b; --accent:#7c3aed; --accent2:#4f46e5; --border:rgba(255,255,255,.08); --shadow:0 18px 45px rgba(0,0,0,.45); --radius:18px; }
    *{ box-sizing:border-box; }
    body{ margin:0; font-family: ui-sans-serif, system-ui, -apple-system, sans-serif; background: radial-gradient(1200px 600px at 20% 10%, rgba(124,58,237,.25), transparent 60%), radial-gradient(1000px 500px at 90% 40%, rgba(79,70,229,.22), transparent 60%), var(--bg); color:var(--text); min-height:100vh; }
    .topbar{ position:sticky; top:0; z-index:50; background: rgba(15,26,51,.72); backdrop-filter: blur(10px); border-bottom:1px solid var(--border); }
    .topbar-inner{ max-width:1100px; margin:0 auto; padding:14px 16px; display:flex; align-items:center; justify-content:space-between; gap:12px; }
    .brand{ display:flex; align-items:center; gap:10px; font-weight:900; letter-spacing:.4px; }
    .brand i{ color: var(--accent); font-size:18px; }
    .sub{ font-weight:700; color:var(--muted); font-size:13px; }
    .actions{ display:flex; align-items:center; gap:10px; }
    .pill{ display:flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid var(--border); border-radius:999px; color:var(--text); background: rgba(255,255,255,.04); font-size:13px; font-weight:800; }
    .logout{ text-decoration:none; border:1px solid rgba(239,68,68,.35); color:#ffd1d1; background: rgba(239,68,68,.10); padding:10px 12px; border-radius:999px; font-weight:900; transition:.2s; }
    .wrap{ max-width:1100px; margin:0 auto; padding:18px 16px 28px; }
    .grid{ display:grid; grid-template-columns: 420px 1fr; gap:16px; }
    @media (max-width: 980px){ .grid{ grid-template-columns: 1fr; } }
    .panel{ background: rgba(16,31,63,.65); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); overflow:hidden; }
    .panel-head{ padding:16px 16px 12px; border-bottom:1px solid var(--border); }
    .panel-head h2{ margin:0; font-size:16px; letter-spacing:.2px; }
    .panel-head p{ margin:6px 0 0; color:var(--muted); font-size:13px; line-height:1.3; font-weight:700; }
    .panel-body{ padding:16px; }
    .reader{ border-radius: 14px; overflow:hidden; border:1px solid var(--border); background:#000; }
    #reader__dashboard_section{ padding:10px !important; background: rgba(255,255,255,.05) !important; border-top:1px solid var(--border) !important; }
    #reader video{ width:100% !important; height:auto !important; object-fit:cover !important; }
    .msg{ padding:12px 14px; border-radius: 14px; border:1px solid var(--border); font-weight:900; margin:0 0 14px; display:flex; align-items:center; gap:10px; line-height:1.25; }
    .msg.success{ background: rgba(34,197,94,.12); border-color: rgba(34,197,94,.28); color:#d7ffe6; }
    .msg.error{ background: rgba(239,68,68,.12); border-color: rgba(239,68,68,.28); color:#ffd4d4; }
    .field{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .input{ flex:1; min-width: 220px; padding:16px 14px; border-radius: 14px; border:1px solid var(--border); background: rgba(255,255,255,.04); color:var(--text); font-size:18px; font-weight:900; outline:none; }
    .btn{ padding:16px 16px; border-radius:14px; border:1px solid var(--border); background: linear-gradient(135deg, rgba(124,58,237,.95), rgba(79,70,229,.95)); color:white; font-size:16px; font-weight:900; cursor:pointer; white-space:nowrap; }
    .btn-ghost{ background: rgba(255,255,255,.06); border:1px solid var(--border); }
    .btn-danger{ background: rgba(239,68,68,.15); border-color: rgba(239,68,68,.35); }
    .booking-card{ display:flex; flex-direction:column; gap:8px; padding:14px; border-radius: 16px; border:1px solid var(--border); background: rgba(255,255,255,.03); }
    .booking-title{ display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
    .badge{ padding:8px 10px; border-radius:999px; font-size:12px; font-weight:900; border:1px solid var(--border); background: rgba(255,255,255,.05); color:var(--muted); }
    .badge.paid{ color:#d7ffe6; border-color: rgba(34,197,94,.28); background: rgba(34,197,94,.10); }
    .badge.pending{ color:#fff0d1; border-color: rgba(245,158,11,.28); background: rgba(245,158,11,.10); }
    .booking-main{ font-size:18px; font-weight:1000; margin:0; }
    .booking-sub{ margin:0; color:var(--muted); font-size:13px; line-height:1.35; font-weight:700; }
    .addon-list{ display:flex; flex-direction:column; gap:10px; margin-top:12px; }
    .addon{ border:1px solid var(--border); background: rgba(255,255,255,.03); border-radius: 16px; padding:12px; display:flex; gap:12px; align-items:stretch; justify-content:space-between; flex-wrap:wrap; }
    .addon-left{ min-width: 220px; flex: 1; }
    .addon-name{ font-weight:1000; margin:0 0 6px; font-size:15px; }
    .addon-meta{ margin:0; color:var(--muted); font-size:12.5px; line-height:1.35; font-weight:700; }
    .stats{ display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
    .stat{ padding:8px 10px; border-radius:999px; border:1px solid var(--border); font-size:12px; font-weight:900; background: rgba(255,255,255,.04); color: var(--muted); }
    .stat strong{ color:var(--text); }
    .remain{ color:#d7ffe6; border-color: rgba(34,197,94,.28); background: rgba(34,197,94,.10); }
    .used{ color:#ffe8c7; border-color: rgba(245,158,11,.28); background: rgba(245,158,11,.10); }
    .done{ color:#ffd4d4; border-color: rgba(239,68,68,.28); background: rgba(239,68,68,.10); }
    .kbd{ display:inline-flex; padding:3px 8px; border-radius: 999px; border:1px solid var(--border); background: rgba(255,255,255,.05); font-weight:900; font-size:12px; color: var(--text); }

    /* IDLE & POPUP OVERLAYS */
    .idle-overlay, .popup-overlay { display:none; position:fixed; inset:0; background: rgba(0,0,0,.65); z-index:9999; align-items:center; justify-content:center; padding:16px; }
    .idle-box, .popup-card { width:min(520px, 100%); background: rgba(16,31,63,.96); border:1px solid var(--border); border-radius: 18px; box-shadow: var(--shadow); padding:18px; text-align:center; position:relative; overflow:hidden; }
    .idle-title { font-size:18px; font-weight:1000; margin:0 0 8px; }
    .idle-sub { margin:0 0 14px; color: var(--muted); font-weight:700; font-size:13px; }
    .idle-actions, .popup-actions { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }

    .popup-topline{ position:absolute; top:0; left:0; height:5px; width:100%; background: linear-gradient(90deg, rgba(34,197,94,.95), rgba(16,185,129,.95)); }
    .popup-topline.error{ background: linear-gradient(90deg, rgba(239,68,68,.95), rgba(245,158,11,.95)); }
    .popup-icon{ width:64px; height:64px; display:flex; align-items:center; justify-content:center; border-radius:18px; margin:8px auto 10px; background: rgba(34,197,94,.12); border:1px solid rgba(34,197,94,.28); color:#d7ffe6; font-size:28px; }
    .popup-icon.error{ background: rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.28); color:#ffd4d4; }
    .popup-title{ margin:0 0 8px; font-size:18px; font-weight:1000; }
    .popup-text{ margin:0 0 14px; color: var(--muted); font-weight:700; font-size:13px; line-height:1.45; }
    .popup-mini{ margin-top:10px; font-size:12px; color: rgba(167,179,214,.85); font-weight:700; }

    /* --- NEW PAYMENT STYLES FOR KIOSK --- */
    .payment-option {
        display: flex; align-items: center; padding: 15px; border: 1px solid rgba(255,255,255,0.1);
        border-radius: 12px; margin-bottom: 10px; cursor: pointer; transition: 0.2s;
        background: rgba(255,255,255,0.03); color: #fff;
    }
    .payment-option:hover { border-color: var(--accent); background: rgba(255,255,255,0.06); }
    .payment-option input { display: none; }
    .radio-dot {
        width: 20px; height: 20px; border: 2px solid rgba(255,255,255,0.4); border-radius: 50%;
        margin-right: 15px; position: relative; flex-shrink: 0;
    }
    .payment-option input:checked + .radio-dot { border-color: var(--ok); }
    .payment-option input:checked + .radio-dot::after {
        content: ''; width: 10px; height: 10px; background: var(--ok); border-radius: 50%;
        position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
    }
    .pay-icons { margin-left: auto; display: flex; align-items: center; gap: 8px; font-size: 1.2rem; }
    .tabby-text { font-size: 0.8rem; color: var(--muted); display: block; margin-top: 2px; line-height: 1.2; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="topbar-inner">
    <div class="brand">
      <i class="fa-solid fa-qrcode"></i>
      <div>
        <div><?php echo htmlspecialchars($targetName); ?> Scanner</div>
        <div class="sub">This page only accepts <span class="kbd"><?php echo htmlspecialchars($targetPid); ?></span></div>
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
            Step 2: Scan <?php echo htmlspecialchars($targetName); ?> QR to redeem
          <?php endif; ?>
        </h2>
        <p>
          <?php if ($step === 1): ?>
            Scan the main ticket ID or Ticket QR (e.g. <span class="kbd">1001-01-A7C</span>)
          <?php else: ?>
            Scan add-on QR like:
            <span class="kbd"><?php echo !empty($booking) ? (int)$booking['booking_id'] : 12; ?>-<?php echo htmlspecialchars($targetPid); ?></span>
            (Auto-redeem 1)
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
              <input class="input" type="text" name="scan_main" id="scan_input" placeholder="Scan MAIN ticket..." autocomplete="off" autofocus inputmode="none" onblur="this.focus()">
              <button class="btn" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Verify</button>
            </div>
          </form>
        <?php else: ?>
          <form method="POST" id="scanAddonForm" style="margin-top:12px;">
            <input type="hidden" name="booking_id" value="<?php echo (int)$booking['booking_id']; ?>">
            <div class="field">
              <input class="input" type="text" name="scan_addon" id="scan_input" placeholder="Scan <?php echo htmlspecialchars($targetName); ?> QR..." autocomplete="off" autofocus inputmode="none" onblur="this.focus()">
              <button class="btn" type="submit"><i class="fa-solid fa-bolt"></i> Redeem</button>

              <button class="btn btn-ghost" type="button" onclick="resetScanner()">
                <i class="fa-solid fa-rotate-left"></i> New Scan
              </button>

              <!--<button class="btn btn-ghost" type="button" onclick="openBuyModal()">
                <i class="fa-solid fa-cart-plus"></i> Buy Add-ons
              </button>-->
            </div>
          </form>
        <?php endif; ?>

      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <h2>Add-ons</h2>
        <p>All add-ons are shown here. This page will only redeem <b><?php echo htmlspecialchars($targetName); ?></b>.</p>
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
                    <p class="addon-name">
                      <?php echo htmlspecialchars($a['name']); ?>
                      <span style="opacity:.7; font-weight:900;">(<?php echo htmlspecialchars($a['product_id']); ?>)</span>
                      <?php if (strcasecmp($a['product_id'], $targetPid) === 0): ?>
                        <span class="kbd" style="margin-left:8px; border-color:rgba(124,58,237,.7);">THIS SCANNER</span>
                      <?php endif; ?>
                    </p>
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

<div class="popup-overlay" id="popupOverlay">
  <div class="popup-card" id="popupCard">
    <div class="popup-topline" id="popupTopline"></div>
    <div class="popup-icon" id="popupIcon"><i class="fa-solid fa-circle-check"></i></div>
    <p class="popup-title" id="popupTitle">Done!</p>
    <p class="popup-text" id="popupText">Message</p>
    <div class="popup-actions">
      <button class="btn" type="button" onclick="closePopup()"><i class="fa-solid fa-check"></i> OK</button>
    </div>
    <div class="popup-mini" id="popupMini">Ready for the next scan…</div>
  </div>
</div>

<div class="popup-overlay" id="buyOverlay" style="display:none;">
  <div class="popup-card" style="text-align:left;">
    <div class="popup-topline"></div>
    <p class="popup-title" style="text-align:center; margin-top:10px;"><i class="fa-solid fa-cart-plus"></i> Purchase More Add-ons</p>
    <p class="popup-text" style="text-align:center;">Select add-ons and quantity, then proceed to payment.</p>
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
              <input class="input" style="width:90px; min-width:90px; padding:12px 10px; text-align:center; font-size:16px;" type="number" min="0" name="qty[<?php echo htmlspecialchars($p['product_id']); ?>]" id="qty_<?php echo htmlspecialchars($p['product_id']); ?>" value="0" oninput="calcBuyTotal()">
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
        <button class="btn btn-ghost btn-danger" type="button" onclick="closeBuyModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
        <button class="btn" type="button" onclick="openCheckoutModal()"><i class="fa-solid fa-arrow-right"></i> Review & Pay</button>
      </div>
    </form>
  </div>
</div>

<div class="popup-overlay" id="checkoutOverlay" style="display:none; z-index:10001;">
  <div class="popup-card" style="text-align:left; width: 420px;">
    <div class="popup-topline"></div>

    <h3 style="text-align:center; margin:15px 0 5px; color:#fff;">Confirm Purchase</h3>
    <p style="text-align:center; color:#a7b3d6; font-size:13px; margin-bottom:20px;">
      Add to Booking #<span id="co_booking_id_disp"></span>
    </p>

   <form action="process.php" method="POST" id="finalCheckoutForm">

      <input type="hidden" name="kiosk_mode" value="1">
      <input type="hidden" name="booking_id" id="co_booking_id_val">
      <input type="hidden" name="return_url" value="<?php echo basename($_SERVER['PHP_SELF']); ?>">

      <div id="hidden_inputs_container"></div>

      <div id="checkoutSummaryList" style="background:rgba(255,255,255,0.05); border-radius:12px; padding:15px; max-height:200px; overflow-y:auto; margin-bottom:15px;"></div>

      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; font-weight:900; font-size:1.2rem;">
        <span>Total Pay</span>
        <span style="color:#22c55e;">AED <span id="co_total_disp">0.00</span></span>
      </div>

      <div style="margin-bottom:20px;">

        <label class="payment-option">
          <input type="radio" name="payment_method" value="card" checked>
          <div class="radio-dot"></div>
          <strong>Credit / Debit Card</strong>
          <div class="pay-icons" style="color:#003B72;">
             <i class="fa-brands fa-cc-visa"></i> <i class="fa-brands fa-cc-mastercard"></i>
          </div>
        </label>

        <label class="payment-option">
          <input type="radio" name="payment_method" value="apple_pay">
          <div class="radio-dot"></div>
          <strong>Apple Pay</strong>
          <div class="pay-icons" style="color:white;"><i class="fa-brands fa-apple"></i></div>
        </label>

        <label class="payment-option">
          <input type="radio" name="payment_method" value="google_pay">
          <div class="radio-dot"></div>
          <strong>Google Pay</strong>
          <div class="pay-icons" style="color:#EA4335;"><i class="fa-brands fa-google-pay"></i></div>
        </label>

        <label class="payment-option">
          <input type="radio" name="payment_method" value="tabby">
          <div class="radio-dot"></div>
          <div>
             <strong style="display:block;">Pay in 4. No interest.</strong>
             <span class="tabby-text">4 payments of AED <strong id="tabby_amount_disp">0.00</strong></span>
          </div>
          <div class="pay-icons">
             <img src="Images/tabby_logo.jpg" alt="Tabby" style="height:20px; width:auto; border-radius:4px;">
          </div>
        </label>

      </div>

      <div class="popup-actions">
        <button class="btn btn-ghost btn-danger" type="button" onclick="closeCheckoutModal()">Back</button>
        <button class="btn" type="submit" style="background:#22c55e; border-color:#22c55e;">Confirm Payment</button>
      </div>
    </form>
  </div>
</div>
<script>
  // ---------------------------
  // IDLE TIMER (10s countdown)
  // ---------------------------
  const IDLE_AFTER_MS = 30 * 1000; // ilang seconds walang activity bago lumabas (example 30s)
  const IDLE_COUNTDOWN_SEC = 10;   // ✅ gusto mo 10 seconds countdown

  let idleTimeout = null;
  let idleInterval = null;
  let idleRemaining = IDLE_COUNTDOWN_SEC;

  const idleOverlay = document.getElementById("idleOverlay");
  const idleBox = idleOverlay ? idleOverlay.querySelector(".idle-box") : null;

  // add countdown line in UI (once)
  function ensureIdleCountdownUI(){
    if(!idleBox) return;
    if(document.getElementById("idleCountdown")) return;

    const p = document.createElement("p");
    p.id = "idleCountdown";
    p.style.margin = "0 0 14px";
    p.style.fontWeight = "1000";
    p.style.fontSize = "16px";
    p.innerHTML = `Resetting in <span id="idleSec">${IDLE_COUNTDOWN_SEC}</span> sec...`;
    // insert after subtitle
    const sub = idleBox.querySelector(".idle-sub");
    if(sub && sub.nextSibling) idleBox.insertBefore(p, sub.nextSibling);
    else idleBox.appendChild(p);
  }

  function showIdle(){
    if(!idleOverlay) return;
    ensureIdleCountdownUI();

    idleRemaining = IDLE_COUNTDOWN_SEC;
    const secEl = document.getElementById("idleSec");
    if(secEl) secEl.textContent = idleRemaining;

    idleOverlay.style.display = "flex";

    clearInterval(idleInterval);
    idleInterval = setInterval(()=>{
      idleRemaining--;
      if(secEl) secEl.textContent = idleRemaining;

      if(idleRemaining <= 0){
        clearInterval(idleInterval);
        // reset page (fresh scan)
        resetScanner();
      }
    }, 1000);
  }

  function hideIdle(){
    if(!idleOverlay) return;
    idleOverlay.style.display = "none";
    clearInterval(idleInterval);
    idleInterval = null;
  }

  function resetIdle(){
    hideIdle();
    clearTimeout(idleTimeout);
    idleTimeout = setTimeout(showIdle, IDLE_AFTER_MS);
  }

  // buttons already exist in your HTML
  function idleContinue(){
    resetIdle();
    const inp = document.getElementById("scan_input");
    if(inp) inp.focus();
  }

  function idleCancel(){
    // cancel => reset immediately
    resetScanner();
  }

  // expose globally (since buttons call them)
  window.resetIdle = resetIdle;
  window.idleContinue = idleContinue;
  window.idleCancel = idleCancel;

  // any activity resets the idle timer
  ["mousemove","mousedown","touchstart","keydown","scroll"].forEach(evt=>{
    window.addEventListener(evt, resetIdle, {passive:true});
  });

  // start idle timer on load
  window.addEventListener("load", resetIdle);
</script>
<script>
  let audioCtx = null;
  let audioUnlocked = false;

  function ensureAudio(){
    if(!audioCtx){ audioCtx = new (window.AudioContext || window.webkitAudioContext)(); }
    if(audioCtx.state === "suspended"){ audioCtx.resume(); }
    audioUnlocked = true;
  }

  function beep(freq, durationMs, type="sine", gain=0.08){
    if(!audioUnlocked) return;
    const o = audioCtx.createOscillator(); const g = audioCtx.createGain();
    o.type = type; o.frequency.value = freq;
    g.gain.setValueAtTime(0.0001, audioCtx.currentTime);
    g.gain.exponentialRampToValueAtTime(gain, audioCtx.currentTime + 0.01);
    g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + (durationMs/1000));
    o.connect(g); g.connect(audioCtx.destination);
    o.start(); o.stop(audioCtx.currentTime + (durationMs/1000) + 0.02);
  }

  function soundSuccess(){ beep(880, 90, "sine", 0.10); setTimeout(()=>beep(1175, 110, "sine", 0.10), 110); }
  function soundError(){ beep(220, 160, "square", 0.08); setTimeout(()=>beep(180, 170, "square", 0.08), 170); }
  function soundReady(){ beep(660, 70, "sine", 0.07); }

  ["click","touchstart","keydown"].forEach(evt=>{
    window.addEventListener(evt, ()=>{ if(!audioUnlocked) ensureAudio(); }, {passive:true, once:true});
  });

  const SOUND = <?php echo json_encode($playSound); ?>;
  const STEP  = <?php echo (int)$step; ?>;

  // ---------------------------
  // ✅ iOS BACK CAMERA + NO DOUBLE SUBMIT
  // ---------------------------
  let qr = null;                 // Html5Qrcode instance
  let scanLocked = false;        // prevents duplicate redeem
  let cameraStarted = false;

  async function stopCamera(){
    try {
      if(qr && cameraStarted){
        await qr.stop();
        cameraStarted = false;
      }
    } catch(e) {}
    try {
      if(qr){
        await qr.clear();
      }
    } catch(e) {}
  }

  function lockAndSubmit(text){
    if(scanLocked) return;
    scanLocked = true;

    try { ensureAudio(); } catch(e){}

    const inp = document.getElementById("scan_input");
    if(inp) inp.value = text;

    // stop camera immediately to prevent “2nd scan” => ALREADY USED
    stopCamera().finally(()=>{
      if (STEP === 1) document.getElementById("scanMainForm").submit();
      else document.getElementById("scanAddonForm").submit();
    });
  }

  async function startScanner(){
    const readerEl = document.getElementById("reader");
    if(!readerEl) return;

    // If already locked (because keyboard submitted), don't start camera.
    if(scanLocked) return;

    // Use Html5Qrcode (NOT Scanner) so we can force back camera reliably
    try {
      qr = new Html5Qrcode("reader");

      // Prefer back camera (environment). Works best on iOS.
      // If it fails, fallback to any available camera.
      const config = {
        fps: 10,
        qrbox: { width: 250, height: 250 },
        // Force back camera on iOS
        videoConstraints: {
          facingMode: { ideal: "environment" }
        }
      };

      await qr.start(
        { facingMode: "environment" }, // hard request environment
        config,
        (decodedText) => lockAndSubmit(decodedText),
        (err) => {}
      );

      cameraStarted = true;

      // iOS safari sometimes needs inline playback
      const vid = document.querySelector("#reader video");
      if(vid){
        vid.setAttribute("playsinline", "true");
        vid.setAttribute("webkit-playsinline", "true");
        vid.muted = true; // helps autoplay/inline rules
      }

    } catch (e1) {
      // fallback: try first available camera
      try {
        const cams = await Html5Qrcode.getCameras();
        if(cams && cams.length){
          const camId = cams[cams.length - 1].id; // usually last is back cam
          await qr.start(
            camId,
            { fps: 10, qrbox: { width: 250, height: 250 } },
            (decodedText) => lockAndSubmit(decodedText),
            (err) => {}
          );
          cameraStarted = true;
        }
      } catch(e2) {
        console.log("Camera not started:", e2);
      }
    }
  }

  // If user presses enter or submits manually, lock + stop camera too
  function attachManualSubmitLock(){
    const inp = document.getElementById("scan_input");
    if(!inp) return;

    const formId = (STEP === 1) ? "scanMainForm" : "scanAddonForm";
    const form = document.getElementById(formId);

    if(form){
      form.addEventListener("submit", ()=>{
        if(scanLocked) return;
        scanLocked = true;
        stopCamera();
      });
    }

    inp.addEventListener("keypress", function(event) {
      if (event.key === "Enter") {
        event.preventDefault();
        const v = this.value.trim();
        if(v !== ""){
          scanLocked = true;
          stopCamera().finally(()=>{
            if (STEP === 1) document.getElementById("scanMainForm").submit();
            else document.getElementById("scanAddonForm").submit();
          });
        }
      }
    });
  }

  window.addEventListener("load", ()=>{
    try { ensureAudio(); } catch(e){}
    if(SOUND === "success") soundSuccess();
    if(SOUND === "error") soundError();
    if(SOUND === "ready") soundReady();

    openPopup();

    const inp = document.getElementById("scan_input");
    if(inp) {
      inp.focus();
      setInterval(() => {
        if(document.activeElement !== inp && document.getElementById('popupOverlay').style.display === 'none') {
          inp.focus();
        }
      }, 1000);
    }

    attachManualSubmitLock();

    // Start camera AFTER page load
    setTimeout(startScanner, 600);

    // Clean URL
    if (window.history.replaceState) {
      const url = new URL(window.location.href);
      if (url.searchParams.has('msg') || url.searchParams.has('popup_show')) {
        const bookingId = url.searchParams.get('booking_id');
        let cleanUrl = window.location.pathname;
        if (bookingId) cleanUrl += '?booking_id=' + bookingId;
        window.history.replaceState(null, '', cleanUrl);
      }
    }

    const msgBox = document.querySelector('.msg');
    if(msgBox) {
      setTimeout(() => {
        msgBox.style.transition = "opacity 0.5s ease";
        msgBox.style.opacity = "0";
        setTimeout(() => msgBox.remove(), 500);
      }, 5000);
    }
  });

  // ✅ Make resetScanner also stop camera cleanly
  function resetScanner() {
    stopCamera().finally(()=>{
      window.location.href = window.location.pathname;
    });
  }

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
      popupTopline.classList.add("error"); popupIcon.classList.add("error");
      popupIcon.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i>';
    } else {
      popupTopline.classList.remove("error"); popupIcon.classList.remove("error");
      popupIcon.innerHTML = '<i class="fa-solid fa-circle-check"></i>';
    }
    popupOverlay.style.display = "flex";

    const inp = document.getElementById("scan_input");
    if(inp) inp.focus();

    if (POPUP.type !== "error") {
      setTimeout(()=>closePopup(), 1500);
    } else {
      setTimeout(()=>closePopup(), 3500);
    }
  }

  function closePopup(){
    popupOverlay.style.display = "none"; resetIdle();
    const inp = document.getElementById("scan_input"); if(inp) inp.focus();
  }
  if(popupOverlay) popupOverlay.addEventListener("click", (e)=>{ if(e.target === popupOverlay) closePopup(); });

  function openBuyModal(){
    const o = document.getElementById('buyOverlay'); if(o) o.style.display = 'flex'; calcBuyTotal();
  }
  function closeBuyModal(){
    const o = document.getElementById('buyOverlay'); if(o) o.style.display = 'none';
    const inp = document.getElementById("scan_input"); if(inp) inp.focus();
  }

  function stepQty(pid, delta){
    const el = document.getElementById('qty_' + pid); if(!el) return;
    let v = parseInt(el.value || '0', 10); v = Math.max(0, v + delta);
    el.value = v; calcBuyTotal();
  }
  function calcBuyTotal(){
    const prices = <?php
      $priceMap = []; foreach($addonCatalog as $p){ $priceMap[$p['product_id']] = (float)$p['price']; }
      echo json_encode($priceMap);
    ?>;
    let total = 0;
    for(const pid in prices){
      const el = document.getElementById('qty_' + pid); if(!el) continue;
      const q = parseInt(el.value || '0', 10) || 0;
      total += (prices[pid] * q);
    }
    document.getElementById('buyTotal').textContent = total.toFixed(2);
  }

  const PRICES = <?php
      $priceMap = [];
      foreach($addonCatalog as $p){ $priceMap[$p['product_id']] = [ 'price' => (float)$p['price'], 'name' => $p['name'] ]; }
      echo json_encode($priceMap);
  ?>;

  function openCheckoutModal() {
    const bookingId = document.querySelector('#buyForm input[name="booking_id"]').value;
    const summaryList = document.getElementById('checkoutSummaryList');
    const hiddenContainer = document.getElementById('hidden_inputs_container');
    const totalDisp = document.getElementById('co_total_disp');

    summaryList.innerHTML = '';
    hiddenContainer.innerHTML = '';
    let total = 0;
    let hasItems = false;

    for (const pid in PRICES) {
        const qtyInput = document.getElementById('qty_' + pid);
        if (qtyInput) {
            const qty = parseInt(qtyInput.value || '0', 10);
            if (qty > 0) {
                hasItems = true;
                const price = PRICES[pid].price;
                const name = PRICES[pid].name;
                const subtotal = price * qty;
                total += subtotal;

                const itemDiv = document.createElement('div');
                itemDiv.style.display = 'flex';
                itemDiv.style.justifyContent = 'space-between';
                itemDiv.style.marginBottom = '5px';
                itemDiv.style.fontSize = '0.9rem';
                itemDiv.innerHTML = `<span>${name} <small style="opacity:0.6;">(x${qty})</small></span><span>${subtotal.toFixed(2)}</span>`;
                summaryList.appendChild(itemDiv);

                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = `qty[${pid}]`;
                hiddenInput.value = qty;
                hiddenContainer.appendChild(hiddenInput);
            }
        }
    }

    if (!hasItems) { alert("Please select at least one item."); return; }

    document.getElementById('co_booking_id_disp').textContent = bookingId;
    document.getElementById('co_booking_id_val').value = bookingId;
    totalDisp.textContent = total.toFixed(2);
    document.getElementById('tabby_amount_disp').textContent = (total / 4).toFixed(2);

    document.getElementById('buyOverlay').style.display = 'none';
    document.getElementById('checkoutOverlay').style.display = 'flex';
  }

  function closeCheckoutModal() {
    document.getElementById('checkoutOverlay').style.display = 'none';
    openBuyModal();
  }
</script>
</body>
</html>
