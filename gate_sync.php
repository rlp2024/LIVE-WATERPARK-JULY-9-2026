<?php
/**
 * gate_sync.php
 * ---------------------------------------------------------------------------
 * Pinapadala (push) ang BAYAD NA booking sa ProDynamics "Online Order" API.
 *
 * MODELO (per-booking):
 *   Isang Online Order kada booking.
 *   OrderKey      = booking_id (numeric)
 *   OrderDetails  = lahat ng tickets + addons (combo meal, locker, etc.)
 *                   - Bawat ticket_instance = isang line (QR sa Remarks)
 *                     -> ito ang sca-scan ng turnstile (gate-entry)
 *                   - Bawat addon = isang line (HINDI gate-scannable;
 *                     report/redemption lang sa loob ng park)
 *   BillDiscAmount   = booking.discount_amount
 *   PaymentDetails   = isang line, Amount = booking.total_amount (KASAMA TAX)
 *
 * Success: Status.ResultCode == "1" (placed) o "2" (already placed, idempotent).
 *
 * LIGTAS: walang ginagawa hangga't blangko ang GATE_AUTH_KEY.
 */

$__gateCfg = __DIR__ . '/gate_config.local.php';
if (is_file($__gateCfg)) require_once $__gateCfg;

if (!defined('GATE_API_URL'))    define('GATE_API_URL',  'http://prodynamicsdxb.dyndns-web.com:5999/Service.svc/OnlineOrder');
if (!defined('GATE_AUTH_KEY'))   define('GATE_AUTH_KEY', '');
if (!defined('GATE_LOG_FILE'))   define('GATE_LOG_FILE', __DIR__ . '/gate_sync.log');
if (!defined('GATE_VERIFY_SSL')) define('GATE_VERIFY_SSL', true);

/**
 * TICKET ProductBarcode (gate-entry tickets) - keyed by ticket_types.type_id.
 *
 *   type_id | barcode    | ticket type                            | price
 *   --------+------------+----------------------------------------+------
 *   7       | FDAYADWS   | Full Day - Adult (with swim)           | 89
 *   8       | FDAYKIDS   | Full Day - Kids                        | 89
 *   9       | FDAYADNS   | Full Day - Adult (without swim)        | 45
 *   12      | FAMKIDSM   | Family Package - Kids w/ Combo Meal    | 93
 *   13      | 3HWKNKDS   | 3 Hours Weekend - Kids                 | 75
 *   14      | 3HWKNAWS   | 3 Hours Weekend - Adult (with swim)    | 75
 *   15      | 3HWKNANS   | 3 Hours Weekend - Adult (without swim) | 40
 *   16      | 3HWKDKDS   | 3 Hours Weekday - Kids                 | 65
 *   17      | 3HWKDAWS   | 3 Hours Weekday - Adult (with swim)    | 65
 *   18      | 3HWKDANS   | 3 Hours Weekday - Adult (without swim) | 30
 */
function gate_barcode_for_type(int $typeId): ?string {
    $map = [
        7  => 'FDAYADWS',  8  => 'FDAYKIDS',  9  => 'FDAYADNS',
        12 => 'FAMKIDSM',
        13 => '3HWKNKDS', 14 => '3HWKNAWS', 15 => '3HWKNANS',   // legacy: 3 Hrs Weekend (DP-RES, naka-deactivate)
        // WK-DAY (types 16/17/18) = na-rename na sa "3 Hours Pass" (isang presyo,
        // All Days). Kaya 3HRP* codes na - tugma sa product identity + mapping PDF.
        16 => '3HRPKIDS', 17 => '3HRPADWS', 18 => '3HRPADNS',
    ];
    return $map[$typeId] ?? null;
}

/**
 * Product-aware na barcode resolver para sa day tickets. Una, susubukan ang
 * fixed na type_id map sa itaas. Kung wala, titingnan ang product_id ng
 * ticket type sa DB - kaya gumagana ito sa BAGONG products (hal. "3 Hours
 * Pass" = 3HRPSS) KAHIT ANONG type_id ang ma-auto-assign, nang hindi
 * kailangang hardcode ang type_id dito.
 *
 *   3HRPSS (3 Hours Pass):  Kids -> 3HRPKIDS
 *                           Adult with swim    -> 3HRPADWS
 *                           Adult without swim -> 3HRPADNS
 */
function gate_barcode_for_ticket_type(PDO $pdo, int $typeId): ?string {
    $b = gate_barcode_for_type($typeId);
    if ($b) return $b;

    $stmt = $pdo->prepare("SELECT product_id, category, sub_label FROM ticket_types WHERE type_id = ? LIMIT 1");
    $stmt->execute([$typeId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;

    $pid = strtoupper(trim((string)$r['product_id']));
    $lbl = strtolower(trim(($r['category'] ?? '') . ' ' . ($r['sub_label'] ?? '')));

    if ($pid === '3HRPSS') {   // 3 Hours Pass (isang presyo, kahit anong araw)
        if (strpos($lbl, 'kid') !== false || strpos($lbl, 'child') !== false) return '3HRPKIDS';
        if (strpos($lbl, 'without') !== false || strpos($lbl, 'w/o') !== false
            || strpos($lbl, 'no swim') !== false || strpos($lbl, 'wo swim') !== false) return '3HRPADNS';
        if (strpos($lbl, 'adult') !== false) return '3HRPADWS';
    }
    return null;
}

/**
 * ADDON ProductBarcode (NON-gate items: F&B, services). Hindi sca-scan ng
 * turnstile, pero pinapadala para makita ng ProDynamics sa kanilang reports.
 *
 *   product_id | barcode    | name
 *   -----------+------------+------------------------------------------
 *   ADD1       | ADOPRKNG   | Parking
 *   ADD2       | ADOZIPLN   | Zipline
 *   ADD3       | ADOLOCKR   | Locker
 *   ADD4       | ADOHBRDG   | Hanging Bridge
 *   ADD5       | ADOPHOTO   | Photography
 *   ADD6       | ADOCMEAL   | Combo Meal Voucher (Pizza/Burger/Pasta)
 */
function gate_addon_barcode_for(string $productId): ?string {
    $map = [
        'ADD1' => 'ADOPRKNG',
        'ADD2' => 'ADOZIPLN',
        'ADD3' => 'ADOLOCKR',
        'ADD4' => 'ADOHBRDG',
        'ADD5' => 'ADOPHOTO',
        'ADD6' => 'ADOCMEAL',
    ];
    return $map[$productId] ?? null;
}

/**
 * MEMBERSHIP / ANNUAL PASS ProductBarcode (GATE-ENTRY - sca-scan sa turnstile).
 * Ang annual pass ay gumagawa ng ticket_instances (may QR) gaya ng regular
 * ticket, kaya kasama ito sa gate-entry lines.
 *
 *   products.product_id | barcode    | pass                 | price
 *   --------------------+------------+----------------------+------
 *   AP1                 | ANPSSLVR   | Silver Annual Pass   | 495
 *   AP2                 | ANPSGOLD   | Gold Annual Pass     | 595
 *   AP3                 | ANPSPLAT   | Platinum Annual Pass | 795
 */
function gate_membership_barcode_for(string $productId): ?string {
    $map = ['AP1' => 'ANPSSLVR', 'AP2' => 'ANPSGOLD', 'AP3' => 'ANPSPLAT'];
    return $map[$productId] ?? null;
}

/** I-resolve ang membership barcode mula sa ticket_type label (Silver/Gold/Platinum). */
function gate_membership_barcode_by_label(string $label): ?string {
    $l = strtolower($label);
    if (strpos($l, 'platinum') !== false) return 'ANPSPLAT';
    if (strpos($l, 'gold')     !== false) return 'ANPSGOLD';
    if (strpos($l, 'silver')   !== false) return 'ANPSSLVR';
    return null;
}

/**
 * FAMILY PACKAGE ProductBarcode (bundle: 2 Adults + 2 Kids + 4 Combo Meals).
 * Dedikadong code para HINDI malito ang family package sa ibang package kahit
 * magkasabay sa iisang booking. Kinikilala mula sa ticket_type label na
 * "Family Package - Adult / Kids" (nilinaw sa booking generation).
 *
 *   FAMPKADT = Family Package - Adult   (gate-entry, may QR)
 *   FAMPKKID = Family Package - Kids    (gate-entry, may QR)
 *   + 4x ADOCMEAL (Combo Meal) bawat family package - kasama na sa 370 price
 */
function gate_family_barcode_by_label(string $label): ?string {
    $l = strtolower($label);
    if (strpos($l, 'family') === false) return null;             // FAM-PKG lang
    if (strpos($l, 'kid') !== false || strpos($l, 'child') !== false) return 'FAMPKKID';
    if (strpos($l, 'adult') !== false) return 'FAMPKADT';
    return null;
}

/**
 * I-resolve ang type_id ng isang ticket_instance gamit ang context ng
 * booking_items (product_id) at ang ticket_type label. Inaalagaan ang
 * ambiguity kapag ang isang product_id ay may multiple ticket_types
 * (hal. DP-FULL = 3 variants).
 *
 * Sumusuporta ng dalawang product_id format sa booking_items:
 *   1. 'type_<id>' (hal. 'type_17')  -> direct ticket_types.type_id lookup
 *   2. 'WK-DAY', 'DP-FULL', etc.     -> lookup via ticket_types.product_id
 */
function gate_resolve_type_id(PDO $pdo, array $productIds, string $label): ?int {
    if (!$productIds) return null;

    $directTypeIds   = [];
    $lookupProductIds = [];
    foreach ($productIds as $pid) {
        if (preg_match('/^type_(\d+)$/', (string)$pid, $m)) {
            $directTypeIds[] = (int)$m[1];
        } else {
            $lookupProductIds[] = $pid;
        }
    }

    $rows = [];
    if ($directTypeIds) {
        $in = implode(',', array_fill(0, count($directTypeIds), '?'));
        $stmt = $pdo->prepare("SELECT type_id, category, sub_label, price FROM ticket_types WHERE type_id IN ($in)");
        $stmt->execute($directTypeIds);
        $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    if ($lookupProductIds) {
        $in = implode(',', array_fill(0, count($lookupProductIds), '?'));
        $stmt = $pdo->prepare("SELECT type_id, category, sub_label, price FROM ticket_types WHERE product_id IN ($in)");
        $stmt->execute($lookupProductIds);
        $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    if (!$rows) return null;

    $L = strtolower(trim($label));
    // Score each candidate row by how well category+sub_label matches the label.
    $best = null; $bestScore = -1;
    foreach ($rows as $r) {
        $full = strtolower(trim(($r['category'] ?? '') . ' ' . ($r['sub_label'] ?? '')));
        $cat  = strtolower(trim((string)($r['category'] ?? '')));
        $score = 0;
        if ($full === $L) $score = 100;
        elseif ($cat === $L) $score = 60;
        elseif ($cat !== '' && strpos($L, $cat) !== false) $score = 40;
        elseif ($cat !== '' && strpos($cat, $L) !== false) $score = 30;
        // Tie-breaker: kung pareho ang score, piliin ang exact type_id na nasa directTypeIds
        if ($score > $bestScore || ($score === $bestScore && in_array((int)$r['type_id'], $directTypeIds, true))) {
            $bestScore = $score; $best = $r;
        }
    }
    return $best ? (int)$best['type_id'] : null;
}

// ===========================================================================
// MAIN: i-push ang isang booking bilang isang Online Order
// ===========================================================================
function gate_push_booking(PDO $pdo, $bookingId): array {
    if (GATE_AUTH_KEY === '') {
        gate_log("SKIP booking $bookingId - walang GATE_AUTH_KEY na naka-set.");
        return ['skipped' => true, 'reason' => 'AuthKey not configured'];
    }

    $stmtB = $pdo->prepare("SELECT * FROM bookings WHERE booking_id = ? LIMIT 1");
    $stmtB->execute([$bookingId]);
    $bk = $stmtB->fetch(PDO::FETCH_ASSOC);
    if (!$bk) return ['error' => 'booking not found'];

    if (($bk['payment_status'] ?? '') !== 'paid') {
        gate_log("SKIP booking $bookingId - hindi pa 'paid' (status=" . ($bk['payment_status'] ?? '') . ").");
        return ['skipped' => true, 'reason' => 'not paid'];
    }

    $totalAmount    = (float)($bk['total_amount']    ?? 0);
    $vatAmount      = (float)($bk['vat_amount']      ?? 0);
    $discountAmount = (float)($bk['discount_amount'] ?? 0);

    $stmtI = $pdo->prepare("SELECT product_id, quantity, price_per_item FROM booking_items WHERE booking_id = ? ORDER BY item_id ASC");
    $stmtI->execute([$bookingId]);
    $items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

    $stmtT = $pdo->prepare("SELECT ticket_id, ticket_code, ticket_type FROM ticket_instances WHERE booking_id = ? ORDER BY ticket_id ASC");
    $stmtT->execute([$bookingId]);
    $tickets = $stmtT->fetchAll(PDO::FETCH_ASSOC);

    // Hatiin ang booking_items: tickets vs addons vs FAMILY PACKAGE bundle
    $ticketProductIds = [];
    $addonItems = [];
    $famPkgQty = 0;            // ilang Family Package sa booking
    $famPkgUnitPrice = 0.0;    // presyo kada Family Package (hal. 370)
    // ORDERED na pila ng type_id mula sa 'type_<id>' na booking_items,
    // pinalawak ayon sa quantity, sa item_id order. Ginagamit ito para
    // POSISYON ang batayan (hindi malabong label) kaya tama kahit magkapareho
    // ang category tulad ng "Kids" (Full Day Kids vs 3 Hours Pass Kids).
    $typeQueue = [];
    foreach ($items as $it) {
        $pid = (string)$it['product_id'];
        if (strpos($pid, 'ADD') === 0) {
            $addonItems[] = $it;
        } elseif (strtoupper($pid) === 'FAM-PKG') {
            // Bundle: hindi isinasama sa generic resolver (nalilito ang ibang
            // package). Hinahawakan via label (FAMPKADT/FAMPKKID) + meals.
            $famPkgQty += max(1, (int)$it['quantity']);
            $famPkgUnitPrice = (float)$it['price_per_item'];
        } else {
            $ticketProductIds[] = $pid;
            if (preg_match('/^type_(\d+)$/', $pid, $mm)) {
                $q = max(1, (int)$it['quantity']);
                for ($k = 0; $k < $q; $k++) $typeQueue[] = (int)$mm[1];
            }
        }
    }
    $ticketProductIds = array_values(array_unique($ticketProductIds));

    $orderDetails = [];
    $serial = 0;

    // --- Tickets: isang line bawat ticket_instance (QR-aware) ---
    foreach ($tickets as $tk) {
        $label = (string)$tk['ticket_type'];
        $price = 0.0;
        $isFamily = false;

        // 0) FAMILY PACKAGE bundle (dedikadong code; di malito sa ibang package)
        $barcode = gate_family_barcode_by_label($label);
        if ($barcode) {
            $isFamily = true;
            // Hatiin ang bundle price (hal. 370) sa 4 na tao; ang 4 meals ay
            // kasama na (ilalabas na Price 0 sa ibaba). 370/4 = 92.50 kada tao.
            $price = $famPkgUnitPrice > 0 ? round($famPkgUnitPrice / 4.0, 2) : 0.0;
        }

        // 1) MEMBERSHIP / ANNUAL PASS (Silver / Gold / Platinum) - gate-entry din
        if (!$barcode) {
            $barcode = gate_membership_barcode_by_label($label);
            if ($barcode) {
                foreach ($ticketProductIds as $pid) {
                    if (gate_membership_barcode_for($pid) === $barcode) {
                        $stmtP = $pdo->prepare("SELECT price FROM products WHERE product_id = ? LIMIT 1");
                        $stmtP->execute([$pid]);
                        $price = (float)($stmtP->fetchColumn() ?: 0);
                        break;
                    }
                }
            }
        }

        // 2) REGULAR TICKET - POSISYON muna (mula sa ordered type_ queue), para
        //    tama kahit magkapareho ang label (hal. Full Day Kids vs 3 Hrs Kids).
        //    Fallback sa label-matching kung ubos na ang queue (hal. product-level
        //    tickets na hindi type_ tulad ng DP-RES/DP-NON o lumang bookings).
        if (!$barcode) {
            $typeId = !empty($typeQueue) ? (int)array_shift($typeQueue)
                                         : gate_resolve_type_id($pdo, $ticketProductIds, $label);
            $barcode = $typeId ? gate_barcode_for_ticket_type($pdo, $typeId) : null;
            if ($typeId) {
                $stmtP = $pdo->prepare("SELECT price FROM ticket_types WHERE type_id = ? LIMIT 1");
                $stmtP->execute([$typeId]);
                $price = (float)($stmtP->fetchColumn() ?: 0);
            }
        }

        if (!$barcode) {
            gate_log("WARN booking $bookingId - di ma-resolve barcode for ticket_code={$tk['ticket_code']} label='{$label}' (product_ids=" . implode(',', $ticketProductIds) . ")");
            continue;
        }
        $serial++;
        $orderDetails[] = [
            'SerialNo'       => $serial,
            'ProductBarcode' => $barcode,
            'Price'          => round($price, 2),
            'Quantity'       => 1,
            'DiscAmount'     => 0,
            'Amount'         => round($price, 2),
            // QR = sca-scan sa gate; may FAM-PKG tag para magrupo ang family lines
            'Remarks'        => 'QR:' . $tk['ticket_code'] . ($isFamily ? ' | FAM-PKG' : ''),
        ];
    }

    // --- Family Package combo meals (4 kada bundle; nasa addon_redemptions,
    //     hindi sa booking_items - kaya idinadagdag dito, hindi madoble) ---
    if ($famPkgQty > 0) {
        $mealBarcode = gate_addon_barcode_for('ADD6'); // ADOCMEAL
        if ($mealBarcode) {
            $serial++;
            $orderDetails[] = [
                'SerialNo'       => $serial,
                'ProductBarcode' => $mealBarcode,
                'Price'          => 0,                 // kasama na sa bundle price
                'Quantity'       => 4 * $famPkgQty,    // 4 combo meals kada family package
                'DiscAmount'     => 0,
                'Amount'         => 0,
                'Remarks'        => 'ADDON | FAM-PKG combo meal (included)',
            ];
        }
    }

    // --- Addons: isang line bawat addon row (combo meal, locker, etc.) ---
    foreach ($addonItems as $it) {
        $pid     = (string)$it['product_id'];
        $qty     = max(1, (int)$it['quantity']);
        $price   = (float)$it['price_per_item'];
        $barcode = gate_addon_barcode_for($pid);
        if (!$barcode) {
            gate_log("WARN booking $bookingId - di ma-resolve addon barcode for $pid; nilaktawan.");
            continue;
        }
        $serial++;
        $orderDetails[] = [
            'SerialNo'       => $serial,
            'ProductBarcode' => $barcode,
            'Price'          => round($price, 2),
            'Quantity'       => $qty,
            'DiscAmount'     => 0,
            'Amount'         => round($price * $qty, 2),
            'Remarks'        => 'ADDON',   // hindi gate-scannable
        ];
    }

    if (!$orderDetails) {
        gate_log("SKIP booking $bookingId - walang ma-resolve na OrderDetails line.");
        return ['skipped' => true, 'reason' => 'no resolvable items'];
    }

    $name  = trim((string)($bk['customer_name'] ?? '')) ?: 'Guest';
    $phone = trim((string)($bk['customer_phone'] ?? '')) ?: '0000000000';

    // Hatiin ang pangalan sa First / Last. Ang LastName ay MANDATORY sa
    // ProDynamics, kaya kung isang salita lang, gagamitin din ito bilang LastName.
    $nameParts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
    $firstName = $nameParts ? array_shift($nameParts) : 'Guest';
    $lastName  = $nameParts ? implode(' ', $nameParts) : $firstName;

    $payload = [
        'AuthKey'        => GATE_AUTH_KEY,
        'OrderKey'       => (string)$bookingId,        // isang order kada booking
        'Remarks'        => 'AWP booking #' . $bookingId . ' | tickets=' . count($tickets) . ' addons=' . count($addonItems) . ' VAT=' . round($vatAmount, 2),
        'LoyaltyPoints'  => 0,
        'BillDiscAmount' => round($discountAmount, 2),
        'OrderDetails'   => $orderDetails,
        'CustomerDetails' => [
            'CustomerKey' => (string)$bookingId,       // numeric (vendor requirement)
            'FirstName'   => $firstName,
            'LastName'    => $lastName,
            'Gender'      => '',
            'MobileNo'    => $phone,
            'LandLine'    => '',
            'AddressDetails' => [
                'AddressKey'  => '1',
                'BuildingName'=> 'Ajman Water Park',
                'FloorNo'     => '',
                'ApartmentNo' => '1',
                'LandMark'    => '',
                'Area'        => 'Ajman',
                'City'        => 'Ajman',
                'Country'     => 'UAE',
                'Remarks'     => '',
                'GPSCoordinate' => '',
            ],
        ],
        'PaymentDetails' => [[
            'PaymentType'       => 1, // 1-Cash, 2-Card, 3-COD
            'Amount'            => round($totalAmount, 2),  // KASAMA NA ANG TAX
            'CreditCardType'    => 0,
            'CreditCardRemarks' => '',
        ]],
    ];

    $result = gate_post($payload);
    gate_log("PUSH booking $bookingId - " . count($orderDetails) . " line(s) "
        . "(tickets=" . count($tickets) . ", addons=" . count($addonItems) . "), "
        . "total=$totalAmount, vat=$vatAmount, disc=$discountAmount: " . json_encode($result));
    return $result;
}

// ===========================================================================
// HTTP POST sa API
// ===========================================================================
function gate_post(array $payload): array {
    $ch = curl_init(GATE_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => GATE_VERIFY_SSL,
        CURLOPT_SSL_VERIFYHOST => GATE_VERIFY_SSL ? 2 : 0,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) return ['ok' => false, 'error' => $err];

    $j  = json_decode($resp, true);
    $rc = $j['Status']['ResultCode'] ?? null;
    return ['ok' => ($rc === '1' || $rc === '2'), 'http' => $http, 'result_code' => $rc, 'raw' => $resp];
}

function gate_log(string $msg): void {
    @file_put_contents(GATE_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

/* ===========================================================================
 * PAANO GAMITIN:
 *   require_once __DIR__ . '/gate_sync.php';
 *   gate_push_booking($pdo, $booking_id);   // tawagin matapos maging 'paid'
 * =========================================================================== */
